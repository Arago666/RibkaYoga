<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\Facets;
use App\Modules\Catalog\Services\Names;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportAsanas extends Command
{
    protected $signature = 'ribka:import-asanas
        {manifest : Путь к локальному JSON-манифесту}
        {--workspace= : UUID рабочего пространства}
        {--owner= : Email владельца этого пространства}
        {--dry-run : Проверить манифест и пути файлов без записи данных}';

    protected $description = 'Добавить асаны из локальных файлов; уже импортированные карточки не изменяются';

    public function handle(WorkspaceContext $workspace, AssetStore $assets, CollectionMembership $collections, Facets $facets): int
    {
        $options = Validator::make([
            'workspace' => $this->option('workspace'), 'owner' => $this->option('owner'),
        ], ['workspace' => ['required', 'uuid'], 'owner' => ['required', 'email', 'max:255']])->validate();
        $owner = User::where('email', mb_strtolower(trim($options['owner'])))->first();
        if (! $owner) {
            $this->error('Владелец с такой электронной почтой не найден.');

            return self::FAILURE;
        }

        // A console-only identity, without creating a session or changing any account.
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($owner);
        try {
            $access = $workspace->details();
            if ($access['role'] !== 'owner' || $access['id'] !== strtolower($options['workspace'])) {
                $this->error('Указанный аккаунт должен быть владельцем указанного пространства.');

                return self::FAILURE;
            }
            [$manifest, $directory] = $this->readManifest();

            return $this->import($manifest, $directory, $workspace, $assets, $collections, $facets);
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
        }
    }

    private function readManifest(): array
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Укажите читаемый локальный JSON-манифест размером до 1 МБ.']);
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['manifest' => 'Не удалось прочитать JSON-манифест.']);
        }
        if (! is_array($manifest)) {
            throw ValidationException::withMessages(['manifest' => 'Манифест должен содержать объект source и items.']);
        }
        $facetKeys = [];
        foreach (Facets::VALUES as $dimension => $values) {
            foreach (array_keys($values) as $key) {
                $facetKeys[] = $dimension.'.'.$key;
            }
        }
        $data = Validator::make($manifest, [
            'source' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'items.*' => ['required', 'array:source_key,name,description,aliases,image,alternate_name,information_description,teacher_cues,benefits,equipment,precautions,editorial,sanskrit_cyrillic,sanskrit_latin,classification_status,facet_keys'],
            'items.*.source_key' => ['required', 'string', 'max:190', 'distinct:strict', 'regex:~^[A-Za-z0-9][A-Za-z0-9_.:/-]*$~'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:4000'],
            'items.*.aliases' => ['sometimes', 'array', 'list', 'max:20'],
            'items.*.aliases.*' => ['required', 'string', 'max:160'],
            'items.*.image' => ['required', 'string', 'max:500'],
            'items.*.alternate_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'items.*.information_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items.*.sanskrit_cyrillic' => ['sometimes', 'nullable', 'string', 'max:160'],
            'items.*.sanskrit_latin' => ['sometimes', 'nullable', 'string', 'max:160'],
            'items.*.teacher_cues' => ['sometimes', 'array', 'list', 'max:30'],
            'items.*.teacher_cues.*' => ['required', 'string', 'max:1000'],
            'items.*.benefits' => ['sometimes', 'array', 'list', 'max:30'],
            'items.*.benefits.*' => ['required', 'string', 'max:2000', 'regex:/\\S/u'],
            'items.*.equipment' => ['sometimes', 'array', 'list', 'max:20'],
            'items.*.equipment.*' => ['required', 'string', 'max:80'],
            'items.*.precautions' => ['sometimes', 'array', 'list', 'max:30'],
            'items.*.precautions.*' => ['required', 'string', 'max:2000'],
            'items.*.editorial' => ['sometimes', 'array:author,source,reviewed,review_note'],
            'items.*.editorial.author' => ['nullable', 'string', 'max:160'],
            'items.*.editorial.source' => ['nullable', 'string', 'max:2000'],
            'items.*.editorial.reviewed' => ['sometimes', 'boolean'],
            'items.*.editorial.review_note' => ['nullable', 'string', 'max:2000'],
            'items.*.classification_status' => ['sometimes', Rule::in(['unreviewed', 'partial', 'reviewed'])],
            'items.*.facet_keys' => ['sometimes', 'array', 'list', 'max:'.count($facetKeys)],
            'items.*.facet_keys.*' => ['required', 'string', Rule::in($facetKeys)],
        ])->validate();
        if (trim($data['source']) === '') {
            throw ValidationException::withMessages(['source' => 'Укажите название источника карточек.']);
        }
        foreach ($data['items'] as $index => $item) {
            // A double wildcard distinct rule would compare every card in the pack.
            // Scope uniqueness to this card while source_key remains unique across the pack.
            $itemValidator = Validator::make($item, [
                'equipment.*' => ['distinct'], 'facet_keys.*' => ['distinct:strict'],
            ]);
            if ($itemValidator->fails()) {
                $messages = [];
                foreach ($itemValidator->errors()->messages() as $field => $errors) {
                    $messages['items.'.$index.'.'.$field] = $errors;
                }
                throw ValidationException::withMessages($messages);
            }
            if (trim($item['name']) === '') {
                throw ValidationException::withMessages(['items.'.$index.'.name' => 'Название карточки не может быть пустым.']);
            }
            if (count(array_filter($item['facet_keys'] ?? [], fn ($key) => str_starts_with($key, 'difficulty.'))) > 1) {
                throw ValidationException::withMessages(['items.'.$index.'.facet_keys' => 'Выберите не больше одного уровня сложности.']);
            }
        }

        return [$data, dirname($path)];
    }

    private function imagePath(string $directory, string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        if (str_contains($relativePath, "\0") || str_starts_with($relativePath, '/')
            || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $relativePath)) {
            throw ValidationException::withMessages(['image' => 'Изображение должно иметь относительный локальный путь.']);
        }
        $path = realpath($directory.DIRECTORY_SEPARATOR.$relativePath);
        $prefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $inside = $path && (DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix));
        if (! $inside || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['image' => 'Файл не найден либо находится вне каталога манифеста.']);
        }

        return $path;
    }

    private function import(array $manifest, string $directory, WorkspaceContext $workspace, AssetStore $assets, CollectionMembership $collections, Facets $facets): int
    {
        $created = $skipped = $failed = $ready = 0;
        foreach ($manifest['items'] as $item) {
            $asset = null;
            try {
                if ($this->alreadyImported($workspace->id(), $item['source_key'])) {
                    $skipped++;

                    continue;
                }
                $path = $this->imagePath($directory, $item['image']);
                if ($this->option('dry-run')) {
                    $ready++;

                    continue;
                }
                // Local files are explicitly allowed only for this administrative
                // command; Media still validates actual MIME, GD decode and limits.
                $asset = $assets->store(new UploadedFile($path, basename($path), null, UPLOAD_ERR_OK, true));
                DB::transaction(function () use ($item, $manifest, $workspace, $asset, $collections, $facets) {
                    // Collection locks precede card creation, matching interactive uploads.
                    $collections->lockWorkspace();
                    $collectionId = $collections->starterCollection();
                    $facets->ensure($workspace->id());
                    $assignments = $this->facetAssignments($item['facet_keys'] ?? [], $workspace->id(), $facets);
                    $name = trim($item['name']);
                    $aliases = array_values(array_unique(array_filter(array_map('trim', $item['aliases'] ?? []))));
                    $description = trim((string) ($item['description'] ?? ''));
                    $sourceNote = 'Источник: '.trim($manifest['source']).'.';
                    $asana = Asana::create([
                        'workspace_id' => $workspace->id(), 'name' => $name,
                        'normalized_name' => Names::normalize($name),
                        'normalized_search_text' => Names::normalize(implode(' ', [$name, $item['alternate_name'] ?? '', $item['sanskrit_cyrillic'] ?? '', $item['sanskrit_latin'] ?? '', ...$aliases])),
                        'description' => $description === '' ? $sourceNote : $description."\n\n".$sourceNote,
                        'current_image_asset_id' => $asset['id'], 'revision' => 1,
                        'created_by' => $workspace->userId(), 'updated_by' => $workspace->userId(),
                        'import_key' => $item['source_key'], 'import_source' => trim($manifest['source']),
                        ...array_intersect_key($item, array_flip(['alternate_name', 'information_description', 'teacher_cues', 'benefits', 'equipment', 'precautions', 'editorial', 'sanskrit_cyrillic', 'sanskrit_latin', 'classification_status'])),
                    ]);
                    $asana->aliases()->createMany(array_map(fn ($alias) => [
                        'name' => $alias, 'normalized_name' => Names::normalize($alias),
                    ], $aliases));
                    $facets->sync($asana, $assignments);
                    if ($asana->classification_status === 'reviewed' && count(array_filter($assignments)) !== count(Facets::DIMENSIONS)) {
                        $asana->forceFill(['classification_status' => 'partial'])->save();
                    }
                    $collections->addAsana($asana->id, [$collectionId]);
                });
                $created++;
                $this->line('Добавлено: '.$item['name']);
            } catch (\Throwable $error) {
                if ($asset) {
                    $assets->discardUnattached($asset['id']);
                }
                if ($error instanceof UniqueConstraintViolationException && $this->alreadyImported($workspace->id(), $item['source_key'])) {
                    $skipped++;

                    continue;
                }
                $failed++;
                $message = $error instanceof ValidationException ? implode(' ', $error->validator->errors()->all()) : 'Не удалось сохранить карточку. Подробности в журнале Laravel.';
                $this->error($item['source_key'].': '.$message);
                if (! $error instanceof ValidationException) {
                    report($error);
                }
            }
        }
        $this->info("Добавлено: {$created}; уже импортировано: {$skipped}; ошибок: {$failed}.");
        if ($this->option('dry-run')) {
            $this->info("Доступных новых файлов: {$ready}. Проверены манифест и пути; декодирование выполняется при импорте. Данные не изменены.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Manifest keys resolve only within the catalog's system dictionary in this workspace. */
    private function facetAssignments(array $keys, string $workspaceId, Facets $facets): array
    {
        $assignments = array_fill_keys(Facets::DIMENSIONS, []);
        if ($keys) {
            $values = Category::where('workspace_id', $workspaceId)->whereIn('system_key', $keys)->orderBy('id')->sharedLock()->get(['id', 'dimension', 'system_key']);
            if ($values->count() !== count($keys)) {
                throw ValidationException::withMessages(['facet_keys' => 'Один из системных признаков недоступен.']);
            }
            foreach ($values as $value) {
                $assignments[$value->dimension][] = $value->id;
            }
        }

        return $facets->validate($assignments, $workspaceId, true);
    }

    private function alreadyImported(string $workspaceId, string $sourceKey): bool
    {
        return Asana::where('workspace_id', $workspaceId)->where('import_key', $sourceKey)->exists();
    }
}
