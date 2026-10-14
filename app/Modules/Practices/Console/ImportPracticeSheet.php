<?php

namespace App\Modules\Practices\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\Http\Requests\DocumentRequest;
use Illuminate\Console\Command;
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

    public function handle(WorkspaceContext $workspace, AsanaReader $asanas, PracticeEditor $editor, Entitlements $limits): int
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
        try {
            $access = $workspace->details();
            if ($access['role'] !== 'owner' || $access['id'] !== strtolower($options['workspace'])) {
                throw ValidationException::withMessages(['owner' => 'Аккаунт должен владеть указанным рабочим пространством.']);
            }
            [$input, $ids] = $this->readManifest($workspace);
            $fingerprint = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            [$ledger, $resultPath, $createdLedger] = $this->openLedger($options['result']);
            if (! $createdLedger) {
                $existing = $this->readLedger($ledger, $workspace, $input['title'], $fingerprint);
                $document = $editor->document($existing['document']['id']);
                $this->info('Этот лист уже импортирован. Повторная практика не создавалась.');
                $this->line('Практика: '.$document['id'].' — '.$document['title']);

                return self::SUCCESS;
            }
            $dryRun = (bool) $this->option('dry-run');
            $result = DB::transaction(function () use ($input, $ids, $workspace, $asanas, $editor, $limits, $dryRun, $ledger, $fingerprint) {
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
                    foreach (array_chunk($ids, 100) as $chunk) {
                        foreach ($editor->resolveCards($chunk) as $snapshot) {
                            $snapshots[$snapshot['source_asana_id']] = $snapshot['id'];
                        }
                    }
                    $tree = $this->documentTree($input, $snapshots);
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
            'blocks.*.rows.*.cells.*' => ['required', 'array:id,asana_id,custom_name,note,group_key,arrow_direction'],
            'blocks.*.rows.*.cells.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'blocks.*.rows.*.cells.*.asana_id' => ['present', 'nullable', 'uuid'],
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

        return [$input, array_values(array_unique($asanaIds))];
    }

    private function documentTree(array $input, array $snapshots): array
    {
        $tree = ['title' => $input['title'], 'theme' => $input['theme'] ?? null, 'blocks' => $input['blocks']];
        foreach ($tree['blocks'] as &$block) {
            foreach ($block['rows'] as &$row) {
                foreach ($row['cells'] as &$cell) {
                    $cell['card_snapshot_id'] = $cell['asana_id'] ? $snapshots[$cell['asana_id']] : null;
                    unset($cell['asana_id']);
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
