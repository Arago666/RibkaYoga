<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\DrawingSource;
use App\Modules\Catalog\Services\Names;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportDrawingPack extends Command
{
    protected $signature = 'ribka:import-drawing-pack
        {manifest : JSON-манифест с PNG и исходниками ribka-drawing}
        {--workspace= : UUID рабочего пространства}
        {--owner= : Email владельца этого пространства}
        {--dry-run : Проверить пакет без сохранения карточек и файлов Media}
        {--result= : Новый JSON-файл с соответствием ключей и UUID карточек}';

    protected $description = 'Атомарно обновить рисунки каталога и добавить редактируемые карточки из локального пакета';

    public function handle(WorkspaceContext $workspace, AssetStore $assets, CollectionMembership $collections, DrawingSource $sources, Entitlements $limits): int
    {
        $options = Validator::make([
            'workspace' => $this->option('workspace'), 'owner' => $this->option('owner'),
        ], ['workspace' => ['required', 'uuid'], 'owner' => ['required', 'email', 'max:255']])->validate();
        $owner = User::where('email', mb_strtolower(trim($options['owner'])))->firstOrFail();
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($owner);
        $pending = [];
        try {
            $access = $workspace->details();
            if ($access['role'] !== 'owner' || $access['id'] !== strtolower($options['workspace'])) {
                throw ValidationException::withMessages(['owner' => 'Аккаунт должен владеть указанным рабочим пространством.']);
            }
            [$manifest, $items] = $this->readPack($workspace, $sources);
            $resultPath = $this->resultPath();
            $dryRun = (bool) $this->option('dry-run');
            $result = DB::transaction(function () use ($manifest, $items, $workspace, $assets, $collections, $limits, $dryRun, &$pending) {
                if (! $dryRun) {
                    // Same lock order as normal card creation and image replacement.
                    $collections->lockWorkspace();
                    $limits->lock($workspace->id());
                }
                $plan = $this->plan($manifest, $items, $workspace, $assets, ! $dryRun);
                $creating = count(array_filter($plan, fn ($entry) => $entry['status'] === 'create'));
                if ($creating) {
                    $limits->assertCanCreate($workspace->id(), 'asanas', Asana::where('workspace_id', $workspace->id())->whereNull('archived_at')->count(), $creating);
                }
                $saved = [];
                foreach ($plan as $entry) {
                    $item = $entry['item'];
                    $asana = $entry['asana'];
                    $status = $entry['status'];
                    if (! $dryRun && $status !== 'unchanged') {
                        $asset = $assets->store(new UploadedFile($item['image_path'], basename($item['image_path']), null, UPLOAD_ERR_OK, true));
                        $pending[] = $asset['id'];
                        if (! hash_equals($item['image_hash'], $this->assetHash($assets, $asset['id']))) {
                            throw ValidationException::withMessages(['image_file' => 'PNG изменился во время импорта. Повторите проверку пакета.']);
                        }
                        if ($status === 'update') {
                            $asana->forceFill([
                                'current_image_asset_id' => $asset['id'], 'drawing_source' => $item['source'],
                                'revision' => $asana->revision + 1, 'updated_by' => $workspace->userId(),
                            ])->save();
                        } else {
                            $aliases = $item['aliases'];
                            $asana = Asana::create([
                                'workspace_id' => $workspace->id(), 'name' => $item['name'],
                                'normalized_name' => Names::normalize($item['name']),
                                'normalized_search_text' => Names::normalize(implode(' ', [$item['name'], ...$aliases])),
                                'description' => null, 'current_image_asset_id' => $asset['id'],
                                'drawing_source' => $item['source'], 'revision' => 1,
                                'created_by' => $workspace->userId(), 'updated_by' => $workspace->userId(),
                                'import_key' => $item['import_key'], 'import_source' => $manifest['source'],
                            ]);
                            $asana->aliases()->createMany(array_map(fn ($name) => ['name' => $name, 'normalized_name' => Names::normalize($name)], $aliases));
                            $asana->categories()->sync($item['category_ids']);
                            $collections->addAsana($asana->id, [$manifest['collection_id']]);
                        }
                    }
                    $saved[] = [
                        'key' => $item['key'], 'id' => $asana?->id,
                        'revision' => $asana?->revision,
                        'status' => $dryRun && $status !== 'unchanged' ? 'would_'.$status : $status,
                    ];
                }

                return $saved;
            });
            $output = [
                'format' => 'ribka-drawing-pack-result-v1', 'workspace_id' => $workspace->id(),
                'source' => $manifest['source'], 'dry_run' => $dryRun, 'items' => $result,
            ];
            if ($resultPath) {
                $encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $file = @fopen($resultPath, 'x');
                if (! $file) {
                    throw new \RuntimeException('Пакет обработан, но не удалось создать JSON результата. Повторный импорт безопасен.');
                }
                try {
                    if (fwrite($file, $encoded) !== strlen($encoded)) {
                        throw new \RuntimeException('Пакет обработан, но не удалось записать JSON результата. Повторный импорт безопасен.');
                    }
                } finally {
                    fclose($file);
                }
            }
            $counts = array_count_values(array_column($result, 'status'));
            foreach ($counts as $status => $count) {
                $this->line($status.': '.$count);
            }
            $this->info($dryRun ? 'Пакет проверен. Карточки, изображения и подборки не изменены.' : 'Пакет сохранён. Прежние изображения и снимки практик сохранены.');
            if ($resultPath) {
                $this->line('Результат: '.$resultPath);
            }
            if ($dryRun && isset($manifest['collection_id'])) {
                $this->line('Доступность активной подборки окончательно проверяется контрактом Collections при сохранении.');
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // AssetStore discards only fresh unattached assets; committed cards are protected by FKs.
            foreach ($pending as $assetId) {
                $assets->discardUnattached($assetId);
            }
            throw $error;
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
        }
    }

    private function readPack(WorkspaceContext $workspace, DrawingSource $sources): array
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Нужен читаемый локальный JSON-манифест до 4 МБ.']);
        }
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest = Validator::make($manifest, [
            'format' => ['required', 'in:ribka-drawing-pack-v1'],
            'workspace_id' => ['required', 'in:'.$workspace->id()],
            'source' => ['required', 'string', 'max:255'],
            'collection_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'items.*' => ['required', 'array:key,id,expected_revision,import_key,name,aliases,category_ids,image_file,source_file'],
            'items.*.key' => ['required', 'string', 'max:190', 'distinct:strict'],
            'items.*.id' => ['nullable', 'uuid', 'distinct:ignore_case'],
            'items.*.expected_revision' => ['nullable', 'integer', 'min:1'],
            'items.*.import_key' => ['nullable', 'string', 'max:190', 'distinct:strict', 'regex:~^[A-Za-z0-9][A-Za-z0-9_.:/-]*$~'],
            'items.*.name' => ['nullable', 'string', 'max:160'],
            'items.*.aliases' => ['sometimes', 'array', 'list', 'max:20'],
            'items.*.aliases.*' => ['required', 'string', 'max:160'],
            'items.*.category_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:30'],
            'items.*.category_ids.*' => ['required', 'uuid'],
            'items.*.image_file' => ['required', 'string', 'max:500'],
            'items.*.source_file' => ['required', 'string', 'max:500'],
        ])->validate();
        $manifest['source'] = trim($manifest['source']);
        if ($manifest['source'] === '') {
            throw ValidationException::withMessages(['source' => 'Укажите непустое название источника.']);
        }
        $manifest['collection_id'] = isset($manifest['collection_id']) ? strtolower($manifest['collection_id']) : null;
        $items = [];
        $bytes = 0;
        foreach ($manifest['items'] as $index => $item) {
            $updating = ! empty($item['id']);
            if ($updating) {
                if (! isset($item['expected_revision']) || array_key_exists('aliases', $item) || array_key_exists('category_ids', $item) || isset($item['import_key']) || isset($item['name'])) {
                    throw ValidationException::withMessages(['items.'.$index => 'Обновление содержит id и expected_revision; метаданные карточки не меняются.']);
                }
                $item['id'] = strtolower($item['id']);
            } elseif (empty($item['import_key']) || trim($item['name'] ?? '') === '' || empty($item['category_ids']) || ! $manifest['collection_id'] || isset($item['expected_revision'])) {
                throw ValidationException::withMessages(['items.'.$index => 'Новая карточка требует import_key, name, category_ids и collection_id пакета.']);
            } else {
                $item['name'] = trim($item['name']);
                $item['aliases'] = array_values(array_unique(array_map('trim', $item['aliases'] ?? [])));
                $item['category_ids'] = array_values(array_unique(array_map('strtolower', $item['category_ids'])));
            }
            $imagePath = $this->localPath(dirname($path), $item['image_file']);
            $sourcePath = $this->localPath(dirname($path), $item['source_file']);
            if (filesize($imagePath) > (int) config('ribka.upload_max_kb', 10240) * 1024 || filesize($sourcePath) > DrawingSource::MAX_BYTES) {
                throw ValidationException::withMessages(['items.'.$index => 'Превышен размер PNG или исходника рисунка.']);
            }
            $bytes += filesize($imagePath) + filesize($sourcePath);
            if ($bytes > 128 * 1024 * 1024) {
                throw ValidationException::withMessages(['items' => 'Суммарный размер PNG и исходников не должен превышать 128 МБ.']);
            }
            $image = file_get_contents($imagePath);
            $dimensions = @getimagesizefromstring($image);
            if (! $dimensions || $dimensions[0] !== 1024 || $dimensions[1] !== 1024 || ($dimensions['mime'] ?? '') !== 'image/png' || (new \finfo(FILEINFO_MIME_TYPE))->buffer($image) !== 'image/png') {
                throw ValidationException::withMessages(['items.'.$index.'.image_file' => 'Рисунок должен быть настоящим PNG 1024 × 1024.']);
            }
            if (! function_exists('imagecreatefromstring') || ! ($decoded = @imagecreatefromstring($image))) {
                throw ValidationException::withMessages(['items.'.$index.'.image_file' => 'PNG не декодируется; нужен PHP GD.']);
            }
            imagedestroy($decoded);
            $source = $sources->validate(file_get_contents($sourcePath));
            if ($source === null) {
                throw ValidationException::withMessages(['items.'.$index.'.source_file' => 'Исходник рисунка не должен быть null.']);
            }
            $items[] = $item + ['image_path' => $imagePath, 'image_hash' => hash('sha256', $image), 'source' => $source];
        }

        return [$manifest, $items];
    }

    private function plan(array $manifest, array $items, WorkspaceContext $workspace, AssetStore $assets, bool $lock): array
    {
        $query = Asana::where('workspace_id', $workspace->id())->where(function ($query) use ($items) {
            $query->whereIn('id', array_column($items, 'id'))->orWhereIn('import_key', array_column($items, 'import_key'));
        })->orderBy('id');
        $existing = ($lock ? $query->lockForUpdate() : $query)->get();
        $byId = $existing->keyBy('id');
        $byKey = $existing->filter(fn ($asana) => $asana->import_key !== null)->keyBy('import_key');
        $categoryIds = array_values(array_unique(array_merge(...array_map(fn ($item) => $item['category_ids'] ?? [], $items))));
        if ($categoryIds) {
            $categories = Category::where('workspace_id', $workspace->id())->whereIn('id', $categoryIds)->orderBy('id');
            if (($lock ? $categories->sharedLock() : $categories)->get(['id'])->count() !== count($categoryIds)) {
                throw ValidationException::withMessages(['category_ids' => 'Категория недоступна в указанном рабочем пространстве.']);
            }
        }
        $plan = [];
        $resolvedTargets = [];
        foreach ($items as $item) {
            $updating = ! empty($item['id']);
            $asana = $updating ? $byId->get($item['id']) : $byKey->get($item['import_key']);
            if ($updating && ! $asana) {
                throw ValidationException::withMessages(['id' => 'Карточка для обновления отсутствует в указанном пространстве: '.$item['key']]);
            }
            if ($asana) {
                if (isset($resolvedTargets[$asana->id])) {
                    throw ValidationException::withMessages(['items' => 'Одна карточка указана в пакете несколько раз: '.$resolvedTargets[$asana->id].' и '.$item['key'].'. Оставьте для неё один элемент.']);
                }
                $resolvedTargets[$asana->id] = $item['key'];
            }
            $status = $updating ? 'update' : 'create';
            if ($asana) {
                // JSON storage may reorder object keys or round 1.0 to 1; numeric equality is intentional.
                $sameDrawing = $asana->drawing_source == $item['source'] && hash_equals($item['image_hash'], $this->assetHash($assets, $asana->current_image_asset_id));
                if (! $updating && ($asana->import_source !== $manifest['source'] || ! $sameDrawing)) {
                    throw ValidationException::withMessages(['import_key' => 'Ключ уже занят другой карточкой или рисунком: '.$item['import_key']]);
                }
                if ($updating && $asana->revision !== (int) $item['expected_revision'] && ! $sameDrawing) {
                    throw ValidationException::withMessages(['expected_revision' => 'Карточка изменена после подготовки пакета: '.$item['key'].'. Обновите экспорт.']);
                }
                if ($sameDrawing) {
                    $status = 'unchanged';
                }
            }
            $plan[] = ['item' => $item, 'asana' => $asana, 'status' => $status];
        }

        return $plan;
    }

    private function assetHash(AssetStore $assets, string $id): string
    {
        $data = $assets->originalData($id);
        $contents = base64_decode(substr($data['data_url'], strpos($data['data_url'], ',') + 1), true);
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать оригинал изображения.');
        }

        return hash('sha256', $contents);
    }

    private function localPath(string $directory, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if (str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $relative)) {
            throw ValidationException::withMessages(['path' => 'Файлы должны иметь относительные локальные пути.']);
        }
        $path = realpath($directory.DIRECTORY_SEPARATOR.$relative);
        $prefix = rtrim(str_replace('\\', '/', $directory), '/').'/';
        $candidate = str_replace('\\', '/', (string) $path);
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $prefix = strtolower($prefix);
        }
        if (! $path || ! str_starts_with($candidate, $prefix) || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['path' => 'Файл отсутствует или находится вне папки пакета: '.$relative]);
        }

        return $path;
    }

    private function resultPath(): ?string
    {
        $path = $this->option('result');
        if (! $path) {
            return null;
        }
        $directory = realpath(dirname($path));
        if (! $directory || ! is_writable($directory) || file_exists($path)) {
            throw ValidationException::withMessages(['result' => 'Результат требует нового файла в существующей доступной папке.']);
        }

        return $directory.DIRECTORY_SEPARATOR.basename($path);
    }
}
