<?php

namespace App\Modules\Practices\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\Http\Requests\DocumentRequest;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportPracticeSheet extends Command
{
    protected $signature = 'ribka:import-practice-sheet
        {manifest : JSON-манифест структуры листа с UUID асан}
        {--workspace= : UUID рабочего пространства}
        {--owner= : Email владельца этого пространства}
        {--dry-run : Проверить лист без создания практики и снимков карточек}
        {--result= : JSON-отчёт и постоянный журнал повторного импорта}';

    protected $description = 'Создать локальную практику из листа, сохраняя группы и независимые снимки карточек';

    public function handle(WorkspaceContext $workspace, AsanaReader $asanas, PracticeEditor $editor, Entitlements $limits, AssetStore $assets): int
    {
        $options = Validator::make([
            'workspace' => $this->option('workspace'), 'owner' => $this->option('owner'), 'result' => $this->option('result'),
        ], ['workspace' => ['required', 'uuid'], 'owner' => ['required', 'email', 'max:255'], 'result' => ['required', 'string', 'max:1000']])->validate();
        $owner = User::where('email', mb_strtolower(trim($options['owner'])))->firstOrFail();
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($owner);
        $ledger = null;
        $resultPath = null;
        $createdLedger = false;
        $committed = false;
        $pendingAssets = [];
        try {
            $access = $workspace->details();
            if ($access['role'] !== 'owner' || $access['id'] !== strtolower($options['workspace'])) {
                throw ValidationException::withMessages(['owner' => 'Аккаунт должен владеть указанным рабочим пространством.']);
            }
            [$input, $ids, $sourceImages] = $this->readManifest($workspace);
            // Legacy manifests retain their existing journal hash. New local files
            // are pinned by content, so replacing one cannot silently replay a sheet.
            $fingerprintInput = $sourceImages ? [
                'manifest' => $input,
                'source_image_hashes' => array_map(fn ($image) => $image['sha256'], $sourceImages),
            ] : $input;
            $fingerprint = hash('sha256', json_encode($fingerprintInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            [$ledger, $resultPath, $createdLedger] = $this->openLedger($options['result']);
            if (! $createdLedger) {
                $existing = $this->readLedger($ledger, $workspace, $input['title'], $fingerprint);
                $document = $editor->document($existing['document']['id']);
                $this->info('Этот лист уже импортирован. Повторная практика не создавалась.');
                $this->line('Практика: '.$document['id'].' — '.$document['title']);

                return self::SUCCESS;
            }
            $dryRun = (bool) $this->option('dry-run');
            $result = DB::transaction(function () use ($input, $ids, $sourceImages, $workspace, $asanas, $editor, $limits, $assets, $dryRun, $ledger, $fingerprint, &$pendingAssets) {
                if (! $dryRun) {
                    $limits->lock($workspace->id());
                }
                $limits->assertCanCreate($workspace->id(), 'practices', DB::table('practice_documents')->where('workspace_id', $workspace->id())->whereNull('archived_at')->count());
                foreach (array_chunk($ids, 100) as $chunk) {
                    foreach ($asanas->findMany($chunk) as $asana) {
                        if (! empty($asana['archived_at'])) {
                            throw ValidationException::withMessages(['asana_ids' => 'Асана находится в архиве.']);
                        }
                    }
                }
                $document = null;
                if (! $dryRun) {
                    $snapshots = [];
                    $canonicalIds = [];
                    foreach ($input['blocks'] as $block) {
                        foreach ($block['rows'] as $row) {
                            foreach ($row['cells'] as $cell) {
                                if ($cell['asana_id'] && ! isset($sourceImages[$cell['id']])) {
                                    $canonicalIds[$cell['asana_id']] = $cell['asana_id'];
                                }
                            }
                        }
                    }
                    foreach (array_chunk(array_values($canonicalIds), 100) as $chunk) {
                        foreach ($editor->resolveCards($chunk) as $snapshot) {
                            $snapshots[$snapshot['source_asana_id']] = $snapshot['id'];
                        }
                    }
                    $sourceSnapshots = [];
                    $imageAssets = [];
                    $importedSnapshots = [];
                    foreach ($input['blocks'] as $block) {
                        foreach ($block['rows'] as $row) {
                            foreach ($row['cells'] as $cell) {
                                $source = $sourceImages[$cell['id']] ?? null;
                                if (! $source) {
                                    continue;
                                }
                                $hash = $source['sha256'];
                                if (! isset($imageAssets[$hash])) {
                                    $asset = $this->storeSourceImage($source, $assets);
                                    $pendingAssets[] = $asset['id'];
                                    $imageAssets[$hash] = $asset['id'];
                                }
                                $key = $cell['asana_id'].':'.$hash;
                                if (! isset($importedSnapshots[$key])) {
                                    $importedSnapshots[$key] = $editor->resolveImportedOriginal($cell['asana_id'], $imageAssets[$hash])['id'];
                                }
                                $sourceSnapshots[$cell['id']] = $importedSnapshots[$key];
                            }
                        }
                    }
                    $tree = $this->documentTree($input, $snapshots, $sourceSnapshots);
                    $document = $editor->copyLocal($tree);
                }
                $result = [
                    'format' => 'ribka-practice-sheet-result-v1', 'workspace_id' => $workspace->id(),
                    'title' => $input['title'], 'manifest_hash' => $fingerprint, 'dry_run' => $dryRun,
                    'card_count' => count($ids), 'cell_count' => $this->cellCount($input['blocks']),
                    'document' => $document ? ['id' => $document['id'], 'title' => $document['title'], 'revision' => $document['revision']] : null,
                ];
                // Flush the ledger before commit; a write failure rolls back document and snapshots.
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (fwrite($ledger, $encoded) !== strlen($encoded) || ! fflush($ledger)) {
                    throw new \RuntimeException('Не удалось записать журнал импорта. Практика не сохранена.');
                }
                if (function_exists('fsync') && ! fsync($ledger)) {
                    throw new \RuntimeException('Не удалось завершить запись журнала импорта. Практика не сохранена.');
                }

                return $result;
            });
            $committed = true;
            $this->info($dryRun ? 'Лист проверен. Практика и снимки карточек не создавались.' : 'Практика сохранена с независимыми снимками карточек.');
            if ($result['document']) {
                $this->line('Практика: '.$result['document']['id'].' — '.$result['document']['title']);
            }
            $this->line('Результат: '.$resultPath);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            if (! $committed) {
                foreach ($pendingAssets as $assetId) {
                    try {
                        $assets->discardUnattached($assetId);
                    } catch (\Throwable $cleanupError) {
                        report($cleanupError);
                    }
                }
            }
            throw $error;
        } finally {
            if (is_resource($ledger)) {
                if ($createdLedger && ! $committed && $resultPath) {
                    // Only the fresh journal created by this operation is removed after rollback.
                    @unlink($resultPath);
                }
                flock($ledger, LOCK_UN);
                fclose($ledger);
            }
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
        }
    }

    private function readManifest(WorkspaceContext $workspace): array
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Нужен читаемый локальный JSON-манифест до 4 МБ.']);
        }
        $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $input = Validator::make($input, [
            'format' => ['required', 'in:ribka-practice-sheet-v1'],
            'workspace_id' => ['sometimes', 'required', 'in:'.$workspace->id()],
            'title' => ['required', 'string', 'max:160'], 'theme' => ['nullable', 'string', 'max:240'],
            'blocks' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_blocks')],
            'blocks.*' => ['required', 'array:id,title,purpose,rows'],
            'blocks.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'blocks.*.title' => ['required', 'string', 'max:160'], 'blocks.*.purpose' => ['nullable', 'string', 'max:240'],
            'blocks.*.rows' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_rows_per_block')],
            'blocks.*.rows.*' => ['required', 'array:id,note,cells'],
            'blocks.*.rows.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'blocks.*.rows.*.note' => ['nullable', 'string', 'max:1000'],
            'blocks.*.rows.*.cells' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_cells_per_row')],
            'blocks.*.rows.*.cells.*' => ['required', 'array:id,asana_id,custom_name,note,group_key,arrow_direction,source_image'],
            'blocks.*.rows.*.cells.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'blocks.*.rows.*.cells.*.asana_id' => ['present', 'nullable', 'uuid'],
            'blocks.*.rows.*.cells.*.source_image' => ['sometimes', 'nullable', 'string', 'max:500'],
            'blocks.*.rows.*.cells.*.custom_name' => ['nullable', 'string', 'max:160'],
            'blocks.*.rows.*.cells.*.note' => ['nullable', 'string', 'max:160'],
            'blocks.*.rows.*.cells.*.group_key' => ['nullable', 'uuid'],
            'blocks.*.rows.*.cells.*.arrow_direction' => ['nullable', 'in:cross,both,up,down,left,right'],
        ])->validate();
        $input['title'] = trim($input['title']);
        if ($input['title'] === '') {
            throw ValidationException::withMessages(['title' => 'Название листа не должно быть пустым.']);
        }
        $entityIds = [];
        $asanaIds = [];
        $sourceImages = [];
        foreach ($input['blocks'] as &$block) {
            $block['id'] = strtolower($block['id']);
            $block['title'] = trim($block['title']);
            if ($block['title'] === '') {
                throw ValidationException::withMessages(['blocks.title' => 'Название блока не должно быть пустым.']);
            }
            $entityIds[] = $block['id'];
            foreach ($block['rows'] as &$row) {
                $row['id'] = strtolower($row['id']);
                $entityIds[] = $row['id'];
                $groups = [];
                foreach ($row['cells'] as $position => &$cell) {
                    $cell['id'] = strtolower($cell['id']);
                    $entityIds[] = $cell['id'];
                    $cell['asana_id'] = isset($cell['asana_id']) ? strtolower($cell['asana_id']) : null;
                    $cell['custom_name'] = trim($cell['custom_name'] ?? '');
                    if ($cell['custom_name'] === '') {
                        $cell['custom_name'] = null;
                    }
                    $cell['group_key'] = isset($cell['group_key']) ? strtolower($cell['group_key']) : null;
                    $cell['arrow_direction'] ??= null;
                    if ($cell['asana_id'] && $cell['custom_name'] !== null) {
                        throw ValidationException::withMessages(['cells' => 'Ячейка содержит асану либо своё название; оба одновременно запрещены.']);
                    }
                    if (! $cell['asana_id'] && $cell['custom_name'] === null && ($cell['note'] ?? '') !== '') {
                        throw ValidationException::withMessages(['cells.note' => 'Пустая ячейка не может содержать подпись.']);
                    }
                    if ($cell['asana_id']) {
                        $asanaIds[] = $cell['asana_id'];
                    }
                    if (isset($cell['source_image'])) {
                        if (! $cell['asana_id']) {
                            throw ValidationException::withMessages(['cells.source_image' => 'Локальный оригинал можно указать только вместе с asana_id.']);
                        }
                        $cell['source_image'] = str_replace('\\', '/', $cell['source_image']);
                        $source = $this->sourceImage(dirname($path), $cell['source_image']);
                        $sourceImages[$cell['id']] = $source;
                    }
                    if ($cell['group_key']) {
                        $groups[$cell['group_key']][] = $position;
                    }
                }
                unset($cell);
                foreach ($groups as $members) {
                    if (count($members) < 2 || count($members) !== $members[count($members) - 1] - $members[0] + 1) {
                        throw ValidationException::withMessages(['cells.group_key' => 'Группа должна содержать минимум две соседние ячейки одной строки.']);
                    }
                }
            }
            unset($row);
        }
        unset($block);
        if (count($entityIds) !== count(array_unique($entityIds))) {
            throw ValidationException::withMessages(['blocks' => 'UUID блоков, строк и ячеек должны быть уникальны во всём листе.']);
        }
        if ($this->cellCount($input['blocks']) > config('sequence.max_cells_per_document')) {
            throw ValidationException::withMessages(['blocks' => 'Слишком много ячеек в одном документе.']);
        }
        // Same HTTP structure rules; source IDs are UUID placeholders only for this read-only validation.
        $request = new DocumentRequest;
        $request->setMethod('POST');
        Validator::make($this->documentTree($input, array_combine($asanaIds, $asanaIds) ?: []), $request->rules())->validate();

        return [$input, array_values(array_unique($asanaIds)), $sourceImages];
    }

    private function sourceImage(string $directory, string $relative): array
    {
        $parts = explode('/', $relative);
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
            || in_array('..', $parts, true) || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $relative)) {
            throw ValidationException::withMessages(['cells.source_image' => 'Изображение должно иметь относительный локальный путь без переходов в родительские каталоги.']);
        }
        $candidate = $directory;
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            $candidate .= DIRECTORY_SEPARATOR.$part;
            if (is_link($candidate)) {
                throw ValidationException::withMessages(['cells.source_image' => 'Символические ссылки в пути изображения не поддерживаются.']);
            }
        }
        $path = realpath($candidate);
        $prefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $inside = $path && (DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix));
        if (! $inside || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['cells.source_image' => 'Изображение не найдено либо находится вне каталога манифеста.']);
        }
        $size = filesize($path);
        $hash = @hash_file('sha256', $path);
        if ($size === false || $size <= 0 || $size > (int) config('ribka.upload_max_kb', 10240) * 1024 || ! is_string($hash)) {
            throw ValidationException::withMessages(['cells.source_image' => 'Нужно читаемое изображение размером не больше 10 МБ.']);
        }

        return ['relative' => $relative, 'path' => $path, 'sha256' => $hash];
    }

    private function storeSourceImage(array $source, AssetStore $assets): array
    {
        $limit = (int) config('ribka.upload_max_kb', 10240) * 1024;
        $contents = @file_get_contents($source['path'], false, null, 0, $limit + 1);
        if (! is_string($contents) || strlen($contents) > $limit || ! hash_equals($source['sha256'], hash('sha256', $contents))) {
            throw ValidationException::withMessages(['cells.source_image' => 'Исходное изображение изменилось после чтения манифеста. Повторите импорт.']);
        }
        $temporary = tmpfile();
        if (! is_resource($temporary)) {
            throw new \RuntimeException('Не удалось подготовить локальное изображение для импорта.');
        }
        try {
            // Store exactly the bytes whose digest is recorded in the journal,
            // rather than reopening a potentially changed source during GD decode.
            if (fwrite($temporary, $contents) !== strlen($contents) || ! fflush($temporary)) {
                throw new \RuntimeException('Не удалось подготовить локальное изображение для импорта.');
            }

            return $assets->store(new UploadedFile(stream_get_meta_data($temporary)['uri'], basename($source['relative']), null, UPLOAD_ERR_OK, true));
        } finally {
            fclose($temporary);
        }
    }

    private function documentTree(array $input, array $snapshots, array $sourceSnapshots = []): array
    {
        $tree = ['title' => $input['title'], 'theme' => $input['theme'] ?? null, 'blocks' => $input['blocks']];
        foreach ($tree['blocks'] as &$block) {
            foreach ($block['rows'] as &$row) {
                foreach ($row['cells'] as &$cell) {
                    $cell['card_snapshot_id'] = $cell['asana_id'] ? ($sourceSnapshots[$cell['id']] ?? $snapshots[$cell['asana_id']]) : null;
                    unset($cell['asana_id'], $cell['source_image']);
                }
                unset($cell);
            }
            unset($row);
        }
        unset($block);

        return $tree;
    }

    private function cellCount(array $blocks): int
    {
        return array_sum(array_map(fn ($block) => array_sum(array_map(fn ($row) => count($row['cells']), $block['rows'])), $blocks));
    }

    private function openLedger(string $path): array
    {
        $directory = realpath(dirname($path));
        if (! $directory || ! is_writable($directory) || is_link($path)) {
            throw ValidationException::withMessages(['result' => 'Укажите обычный JSON-файл в существующей доступной папке.']);
        }
        $path = $directory.DIRECTORY_SEPARATOR.basename($path);
        $file = @fopen($path, 'x+b');
        $created = is_resource($file);
        if (! $created) {
            $file = @fopen($path, 'r+b');
        }
        if (! is_resource($file)) {
            throw ValidationException::withMessages(['result' => 'Не удалось открыть журнал импорта.']);
        }
        if (! flock($file, LOCK_EX | LOCK_NB)) {
            fclose($file);
            if ($created) {
                @unlink($path);
            }
            throw new \RuntimeException('Журнал занят другой операцией. Дождитесь её завершения и повторите команду.');
        }

        return [$file, $path, $created];
    }

    private function readLedger($file, WorkspaceContext $workspace, string $title, string $fingerprint): array
    {
        $size = fstat($file)['size'];
        if ($size <= 0 || $size > 1024 * 1024) {
            throw ValidationException::withMessages(['result' => 'Существующий журнал пуст или повреждён. Укажите новый файл после проверки предыдущего импорта.']);
        }
        $result = json_decode(stream_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $result = Validator::make($result, [
            'format' => ['required', 'in:ribka-practice-sheet-result-v1'],
            'workspace_id' => ['required', 'in:'.$workspace->id()],
            'title' => ['required', Rule::in([$title])],
            'manifest_hash' => ['required', 'in:'.$fingerprint],
            'dry_run' => ['required', 'boolean'],
            'document' => ['required', 'array'], 'document.id' => ['required', 'uuid'],
        ])->validate();
        if ($result['dry_run'] !== false) {
            throw ValidationException::withMessages(['result' => 'Это журнал dry-run. Для сохранения выберите новый файл результата.']);
        }

        return $result;
    }
}
