<?php

namespace App\Modules\Practices\Actions;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Practices\Services\ExecutionParameters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MesocycleEditor
{
    public function __construct(private readonly WorkspaceContext $context, private readonly PracticeEditor $practices, private readonly AsanaReader $asanas, private readonly Entitlements $entitlements) {}

    public function listing(array $filters): array
    {
        $query = DB::table('practice_mesocycles')->where('workspace_id', $this->context->id())->where('kind', $filters['kind'] ?? 'cycle');
        ($filters['archive'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if ($search = trim($filters['q'] ?? '')) {
            $query->whereRaw("replace(lower(title), 'ё', 'е') like ?", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(str_replace('ё', 'е', $search))).'%']);
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($filters['per_page'] ?? 18);
        $ids = array_map(fn ($row) => $row->id, $page->items());
        $counts = DB::table('practice_mesocycle_weeks')->whereIn('mesocycle_id', $ids)->select('mesocycle_id')->selectRaw('count(*) as count')->groupBy('mesocycle_id')->pluck('count', 'mesocycle_id');
        $data = array_map(fn ($record) => array_replace((array) $record, ['cloud_asana_ids' => json_decode($record->cloud_asana_ids, true) ?: [], 'positions_count' => (int) ($counts[$record->id] ?? 0)]), $page->items());

        return ['data' => $data, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    public function show(string $id): array
    {
        $record = $this->find($id);
        $data = (array) $record;
        $data['revision'] = (int) $record->revision;
        $data['cloud_asana_ids'] = json_decode($record->cloud_asana_ids, true) ?: [];
        $data['cloud_asanas'] = $data['cloud_asana_ids'] ? array_values($this->asanas->findMany($data['cloud_asana_ids'], true)) : [];
        $data['weeks'] = DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->orderBy('position')->get()->map(fn ($week) => ['id' => $week->id, 'position' => (int) $week->position, 'title' => $week->title, 'document_id' => $week->document_id, 'document' => $this->practices->document($week->document_id)])->all();

        return $data;
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->entitlements->lock($this->context->id());
            if ($saved = $this->replay($input, 'create')) {
                return $this->show($saved['cycle_id']);
            }
            $count = (int) ($input['count'] ?? 5);
            $kind = $input['kind'] ?? 'cycle';
            $this->quota($count);
            $id = (string) Str::uuid();
            $base = empty($input['base_document_id']) ? null : $this->source($input['base_document_id'], $input['expected_source_revision'] ?? null);
            DB::table('practice_mesocycles')->insert(['id' => $id, 'workspace_id' => $this->context->id(), 'title' => $input['title'], 'theme' => $input['theme'] ?? null, 'kind' => $kind, 'cloud_asana_ids' => '[]', 'revision' => 1, 'created_by' => $this->context->userId(), 'updated_by' => $this->context->userId(), 'created_at' => now(), 'updated_at' => now()]);
            for ($position = 0; $position < $count; $position++) {
                $title = mb_substr($input['title'], 0, 130).' — занятие '.($position + 1);
                $document = $base ? $this->practices->copyForCycle($base['id'], $title, $kind === 'template', $base['revision']) : $this->practices->create(['title' => $title, 'kind' => $kind === 'template' ? 'template' : 'practice', 'theme' => $input['theme'] ?? null]);
                DB::table('practice_mesocycle_weeks')->insert(['id' => (string) Str::uuid(), 'mesocycle_id' => $id, 'document_id' => $document['id'], 'position' => $position, 'title' => null]);
            }
            $this->remember($input, 'create', ['cycle_id' => $id]);

            return $this->show($id);
        });
    }

    public function update(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $record = $this->find($id, true);
            $this->editable($record, $input['expected_revision']);
            $values = [];
            foreach (['title', 'theme'] as $field) {
                if (array_key_exists($field, $input)) {
                    $values[$field] = $input[$field];
                }
            }
            if (array_key_exists('cloud_asana_ids', $input)) {
                $ids = array_map('strtolower', $input['cloud_asana_ids']);
                $previous = json_decode($record->cloud_asana_ids, true) ?: [];
                foreach ($this->asanas->findMany($ids, true) as $card) {
                    if ($card['archived_at'] && ! in_array($card['id'], $previous, true)) {
                        throw ValidationException::withMessages(['cloud_asana_ids' => 'Нельзя добавить архивную асану в облако.']);
                    }
                }
                $values['cloud_asana_ids'] = json_encode($ids, JSON_THROW_ON_ERROR);
            }
            $this->touch($record, $values);

            return $this->show($id);
        });
    }

    public function replaceWeek(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            if ($saved = $this->replay($input, 'replace:'.$id)) {
                return $this->show($saved['cycle_id']);
            }
            $this->editable($record, $input['expected_revision']);
            $slot = DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->where('position', $input['position'])->lockForUpdate()->first();
            abort_unless($slot, 404, 'Позиция цикла не найдена.');
            $source = $this->source($input['source_document_id'], $input['expected_source_revision'] ?? null);
            $copy = $this->practices->copyForCycle($source['id'], mb_substr($record->title, 0, 130).' — занятие '.($slot->position + 1), $record->kind === 'template', $source['revision']);
            DB::table('practice_mesocycle_weeks')->where('id', $slot->id)->update(['document_id' => $copy['id']]);
            $this->touch($record);
            $this->remember($input, 'replace:'.$id, ['cycle_id' => $id, 'document_id' => $copy['id'], 'previous_document_id' => $slot->document_id]);

            return $this->show($id);
        });
    }

    public function structure(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            if ($saved = $this->replay($input, 'structure:'.$id)) {
                return $this->show($saved['cycle_id']);
            }
            $this->editable($record, $input['expected_revision']);
            $existing = DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->lockForUpdate()->get()->keyBy('id');
            $kept = array_filter(array_column($input['positions'], 'id'));
            foreach ($kept as $slotId) {
                abort_unless($existing->has($slotId), 422, 'Позиция принадлежит другому циклу.');
            }
            $removed = $existing->except($kept);
            $decisions = collect($input['removed'] ?? [])->keyBy('id');
            foreach ($removed as $slot) {
                $document = $this->practices->document($slot->document_id);
                $filled = collect($document['blocks'])->flatMap(fn ($block) => $block['rows'])->flatMap(fn ($row) => $row['cells'])->contains(fn ($cell) => $cell['card_snapshot_id'] || $cell['custom_name']);
                $decision = $decisions[$slot->id] ?? null;
                abort_if($filled && ! $decision, 422, 'Подтвердите сохранение или архивирование заполненного занятия.');
                if ($decision) {
                    abort_unless($document['revision'] === (int) $decision['expected_revision'], 409, 'Удаляемое занятие изменилось.');
                    if ($decision['action'] === 'archive') {
                        abort_if(DB::table('practice_mesocycle_weeks')->where('document_id', $slot->document_id)->where('mesocycle_id', '!=', $id)->exists(), 422, 'Занятие используется другим циклом. Сохраните его отдельно.');
                        $this->practices->archive($slot->document_id, $document['revision']);
                    }
                }
            }
            $newCount = count(array_filter($input['positions'], fn ($slot) => empty($slot['id'])));
            $this->entitlements->assertCanCreate($this->context->id(), 'practices', DB::table('practice_documents')->where('workspace_id', $this->context->id())->whereNull('archived_at')->count(), $newCount);
            DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->increment('position', 1000);
            foreach ($input['positions'] as $position => $slot) {
                if (! empty($slot['id'])) {
                    DB::table('practice_mesocycle_weeks')->where('id', $slot['id'])->update(['position' => $position, 'title' => $slot['title'] ?? null]);
                } else {
                    $document = $this->practices->create(['title' => mb_substr($record->title, 0, 130).' — занятие '.($position + 1), 'kind' => $record->kind === 'template' ? 'template' : 'practice']);
                    DB::table('practice_mesocycle_weeks')->insert(['id' => (string) Str::uuid(), 'mesocycle_id' => $id, 'document_id' => $document['id'], 'position' => $position, 'title' => $slot['title'] ?? null]);
                }
            }
            if ($removed->isNotEmpty()) {
                DB::table('practice_mesocycle_weeks')->whereIn('id', $removed->keys())->delete();
            }
            $this->touch($record);
            $this->remember($input, 'structure:'.$id, ['cycle_id' => $id]);

            return $this->show($id);
        });
    }

    /** A preview accepts a drop without mounting another full editor. */
    public function insert(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $record = $this->find($id, true);
            if ($saved = $this->replay($input, 'insert:'.$id)) {
                return $this->show($saved['cycle_id']);
            }
            $this->editable($record, $input['expected_revision']);
            abort_unless(DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->where('document_id', $input['document_id'])->exists(), 404);
            $document = $this->practices->document($input['document_id']);
            abort_unless($document['revision'] === (int) $input['expected_document_revision'], 409, 'Занятие изменилось. Обновите превью.');
            $found = false;
            $before = null;
            foreach ($document['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        if ($cell['id'] !== $input['cell_id']) {
                            continue;
                        }
                        abort_if(($cell['card_snapshot_id'] || $cell['custom_name']) && ! ($input['confirm_replace'] ?? false), 422, 'Подтвердите замену занятой ячейки.');
                        $before = $cell;
                        $snapshot = $this->practices->resolveCards([$input['asana_id']], [], ['style' => $document['illustration_style'] ?? 'original', 'character' => $document['illustration_character'] ?? 'neutral'])[0];
                        $cell = array_replace($cell, ExecutionParameters::values([]), ['card_snapshot_id' => $snapshot['id'], 'custom_name' => null, 'note' => null]);
                        $found = true;
                    }
                    unset($cell);
                }
                unset($row);
            }
            unset($block);
            abort_unless($found, 404, 'Ячейка не найдена.');
            $document['expected_revision'] = $document['revision'];
            $this->practices->save($document['id'], $document);
            $this->remember($input, 'insert:'.$id, ['cycle_id' => $id, 'document_id' => $document['id'], 'after_revision' => $document['revision'] + 1, 'before_cell' => $before]);

            return $this->show($id);
        });
    }

    public function undoInsert(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $record = $this->find($id, true);
            if ($saved = $this->replay($input, 'undo-insert:'.$id)) {
                return $this->show($saved['cycle_id']);
            }
            abort_if($record->archived_at, 409, 'Восстановите цикл.');
            $operation = DB::table('practice_operations')->where('id', $input['operation_id'])->where('workspace_id', $this->context->id())->where('user_id', $this->context->userId())->where('kind', 'insert:'.$id)->lockForUpdate()->first();
            abort_unless($operation, 404);
            $result = json_decode($operation->result, true, 512, JSON_THROW_ON_ERROR);
            abort_if($result['undone'] ?? false, 409, 'Вставка уже отменена.');
            $document = $this->practices->document($result['document_id']);
            abort_unless($document['revision'] === $result['after_revision'], 409, 'Занятие изменилось после вставки. Отмена не должна стирать новые правки.');
            $found = false;
            foreach ($document['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        if ($cell['id'] === $result['before_cell']['id']) {
                            $cell = $result['before_cell'];
                            $found = true;
                        }
                    }
                    unset($cell);
                }
                unset($row);
            }
            unset($block);
            abort_unless($found, 409, 'Ячейка больше не существует.');
            $document['expected_revision'] = $document['revision'];
            $this->practices->save($document['id'], $document);
            $result['undone'] = true;
            DB::table('practice_operations')->where('id', $operation->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $this->remember($input, 'undo-insert:'.$id, ['cycle_id' => $id]);

            return $this->show($id);
        });
    }

    public function duplicate(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            if ($saved = $this->replay($input, 'duplicate:'.$id)) {
                return $this->show($saved['cycle_id']);
            }
            $this->revision($record, $input['expected_revision']);
            abort_if($record->archived_at, 422, 'Восстановите цикл перед копированием.');
            $slots = DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->orderBy('position')->get();
            $this->quota($slots->count());
            $newId = (string) Str::uuid();
            $kind = $input['kind'] ?? 'cycle';
            DB::table('practice_mesocycles')->insert(['id' => $newId, 'workspace_id' => $this->context->id(), 'title' => $input['title'], 'theme' => $record->theme, 'kind' => $kind, 'cloud_asana_ids' => $record->cloud_asana_ids, 'revision' => 1, 'created_by' => $this->context->userId(), 'updated_by' => $this->context->userId(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($slots as $slot) {
                $source = $this->practices->document($slot->document_id);
                $copy = $this->practices->copyForCycle($source['id'], $source['title'], $kind === 'template', $source['revision']);
                DB::table('practice_mesocycle_weeks')->insert(['id' => (string) Str::uuid(), 'mesocycle_id' => $newId, 'document_id' => $copy['id'], 'position' => $slot->position, 'title' => $slot->title]);
            }
            $this->remember($input, 'duplicate:'.$id, ['cycle_id' => $newId]);

            return $this->show($newId);
        });
    }

    public function archive(string $id, int $revision, bool $restore): array
    {
        return DB::transaction(function () use ($id, $revision, $restore) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            $this->revision($record, $revision);
            if ($restore && $record->archived_at) {
                $this->entitlements->assertCanCreate($this->context->id(), 'mesocycles', DB::table('practice_mesocycles')->where('workspace_id', $this->context->id())->whereNull('archived_at')->count());
            }
            $this->touch($record, ['archived_at' => $restore ? null : now()]);

            return $this->show($id);
        });
    }

    private function quota(int $documents): void
    {
        $workspace = $this->context->id();
        $this->entitlements->assertCanCreate($workspace, 'mesocycles', DB::table('practice_mesocycles')->where('workspace_id', $workspace)->whereNull('archived_at')->count());
        $this->entitlements->assertCanCreate($workspace, 'practices', DB::table('practice_documents')->where('workspace_id', $workspace)->whereNull('archived_at')->count(), $documents);
    }

    private function source(string $id, ?int $expected): array
    {
        $source = $this->practices->document(strtolower($id));
        abort_if($source['archived_at'], 422, 'Выберите активную практику или шаблон.');
        abort_unless(in_array($source['kind'], ['practice', 'template'], true), 422, 'Выберите полную практику или шаблон.');
        if ($expected !== null) {
            abort_unless($source['revision'] === $expected, 409, 'Исходная практика изменилась. Обновите выбор.');
        }

        return $source;
    }

    private function replay(array $input, string $kind): ?array
    {
        if (empty($input['idempotency_key'])) {
            return null;
        }
        $operation = DB::table('practice_operations')->where('id', $input['idempotency_key'])->first();
        if (! $operation) {
            return null;
        }
        abort_unless($operation->workspace_id === $this->context->id() && (int) $operation->user_id === $this->context->userId(), 409, 'Ключ операции уже использован.');
        abort_unless($operation->kind === $kind && hash_equals($operation->request_hash, hash('sha256', json_encode($input, JSON_THROW_ON_ERROR))), 409, 'Ключ операции относится к другим данным.');

        return json_decode($operation->result, true, 512, JSON_THROW_ON_ERROR);
    }

    private function remember(array $input, string $kind, array $result): void
    {
        if (empty($input['idempotency_key'])) {
            return;
        }
        DB::table('practice_operations')->insert(['id' => $input['idempotency_key'], 'workspace_id' => $this->context->id(), 'user_id' => $this->context->userId(), 'kind' => $kind, 'request_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function find(string $id, bool $lock = false): object
    {
        $query = DB::table('practice_mesocycles')->where('workspace_id', $this->context->id())->where('id', strtolower($id));
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($record, 404, 'Цикл не найден.');

        return $record;
    }

    private function revision(object $record, int $expected): void
    {
        abort_unless((int) $record->revision === $expected, 409, 'Цикл изменён в другом окне. Обновите страницу.');
    }

    private function editable(object $record, int $expected): void
    {
        $this->revision($record, $expected);
        abort_if($record->archived_at, 409, 'Восстановите цикл перед редактированием.');
    }

    private function touch(object $record, array $values = []): void
    {
        DB::table('practice_mesocycles')->where('id', $record->id)->update($values + ['revision' => $record->revision + 1, 'updated_by' => $this->context->userId(), 'updated_at' => now()]);
    }
}
