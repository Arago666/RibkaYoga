<?php

namespace App\Modules\Practices\Actions;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Billing\PublicApi\Entitlements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MesocycleEditor
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly PracticeEditor $practices,
        private readonly AsanaReader $asanas,
        private readonly Entitlements $entitlements,
    ) {}

    public function listing(array $filters): array
    {
        $query = DB::table('practice_mesocycles')->where('workspace_id', $this->context->id());
        ($filters['archive'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if ($search = trim($filters['q'] ?? '')) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(fn ($query) => $query->where('title', 'like', '%'.$escaped.'%')->orWhere('theme', 'like', '%'.$escaped.'%'));
        }
        $page = $query->orderByDesc('updated_at')->paginate($filters['per_page'] ?? 18);
        $data = array_map(function ($record) {
            $item = (array) $record;
            $item['cloud_asana_ids'] = json_decode($record->cloud_asana_ids, true) ?: [];
            return $item;
        }, $page->items());
        return ['data' => $data, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    public function show(string $id): array
    {
        $record = $this->find($id);
        $data = (array) $record;
        $data['revision'] = (int) $record->revision;
        $data['cloud_asana_ids'] = json_decode($record->cloud_asana_ids, true) ?: [];
        $data['cloud_asanas'] = $data['cloud_asana_ids'] ? array_values($this->asanas->findMany($data['cloud_asana_ids'], true)) : [];
        $weeks = DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->orderBy('position')->get();
        $data['weeks'] = $weeks->map(fn ($week) => [
            'id' => $week->id, 'position' => (int) $week->position,
            'document_id' => $week->document_id, 'document' => $this->practices->document($week->document_id),
        ])->all();
        return $data;
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $workspace = $this->context->id();
            $this->entitlements->lock($workspace);
            $this->entitlements->assertCanCreate($workspace, 'mesocycles', DB::table('practice_mesocycles')->where('workspace_id', $workspace)->whereNull('archived_at')->count());
            $this->entitlements->assertCanCreate($workspace, 'practices', DB::table('practice_documents')->where('workspace_id', $workspace)->whereNull('archived_at')->count(), 5);
            $base = empty($input['base_document_id']) ? null : $this->practices->document(strtolower($input['base_document_id']));
            if ($base && ! empty($base['archived_at'])) {
                throw ValidationException::withMessages(['base_document_id' => 'Базовая практика находится в архиве.']);
            }
            $id = (string) Str::uuid();
            DB::table('practice_mesocycles')->insert([
                'id' => $id, 'workspace_id' => $this->context->id(), 'title' => $input['title'], 'theme' => $input['theme'] ?? null,
                'cloud_asana_ids' => '[]', 'revision' => 1,
                'created_by' => $this->context->userId(), 'updated_by' => $this->context->userId(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            for ($week = 0; $week < 5; $week++) {
                $title = mb_substr($input['title'], 0, 130).' — неделя '.($week + 1);
                $document = $base ? $this->practices->copyLocal(array_replace($base, ['title' => $title, 'theme' => $input['theme'] ?? $base['theme']])) : $this->practices->create(['title' => $title, 'theme' => $input['theme'] ?? null]);
                DB::table('practice_mesocycle_weeks')->insert(['id' => (string) Str::uuid(), 'mesocycle_id' => $id, 'document_id' => $document['id'], 'position' => $week]);
            }
            return $this->show($id);
        });
    }

    public function update(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $record = $this->find($id, true);
            $this->assertRevision($record, $input['expected_revision']);
            abort_if($record->archived_at, 409, 'Восстановите мезоцикл перед редактированием.');
            $ids = array_map('strtolower', $input['cloud_asana_ids']);
            $previous = json_decode($record->cloud_asana_ids, true) ?: [];
            $cards = $this->asanas->findMany($ids, true);
            foreach ($cards as $card) {
                if ($card['archived_at'] && ! in_array($card['id'], $previous, true)) {
                    throw ValidationException::withMessages(['cloud_asana_ids' => 'Нельзя добавить архивную асану в облако.']);
                }
            }
            DB::table('practice_mesocycles')->where('id', $id)->update([
                'title' => $input['title'], 'theme' => $input['theme'] ?? null, 'cloud_asana_ids' => json_encode($ids),
                'revision' => $record->revision + 1, 'updated_by' => $this->context->userId(), 'updated_at' => now(),
            ]);
            return $this->show($id);
        });
    }

    public function replaceWeek(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            $this->assertRevision($record, $input['expected_revision']);
            abort_if($record->archived_at, 409, 'Восстановите мезоцикл перед редактированием.');
            $source = $this->practices->document(strtolower($input['source_document_id']));
            abort_if($source['archived_at'], 422, 'Выберите активную практику или шаблон.');
            $copy = $this->practices->copyLocal(array_replace($source, ['title' => mb_substr($record->title, 0, 130).' — неделя '.($input['position'] + 1)]));
            DB::table('practice_mesocycle_weeks')->where('mesocycle_id', $id)->where('position', $input['position'])->update(['document_id' => $copy['id']]);
            DB::table('practice_mesocycles')->where('id', $id)->update(['revision' => $record->revision + 1, 'updated_by' => $this->context->userId(), 'updated_at' => now()]);
            // Previous week remains a regular practice; replacing a week never deletes its work.
            return $this->show($id);
        });
    }

    public function archive(string $id, int $revision, bool $restore): array
    {
        return DB::transaction(function () use ($id, $revision, $restore) {
            $this->entitlements->lock($this->context->id());
            $record = $this->find($id, true);
            $this->assertRevision($record, $revision);
            if ($restore && $record->archived_at) $this->entitlements->assertCanCreate($this->context->id(), 'mesocycles', DB::table('practice_mesocycles')->where('workspace_id', $this->context->id())->whereNull('archived_at')->count());
            DB::table('practice_mesocycles')->where('id', $id)->update(['archived_at' => $restore ? null : now(), 'revision' => $record->revision + 1, 'updated_by' => $this->context->userId(), 'updated_at' => now()]);
            return $this->show($id);
        });
    }

    private function find(string $id, bool $lock = false): object
    {
        $query = DB::table('practice_mesocycles')->where('workspace_id', $this->context->id())->where('id', strtolower($id));
        if ($lock) $query->lockForUpdate();
        $record = $query->first();
        abort_unless($record, 404, 'Мезоцикл не найден.');
        return $record;
    }

    private function assertRevision(object $record, int $expected): void
    {
        if ((int) $record->revision !== $expected) {
            throw new HttpResponseException(response()->json(['message' => 'Мезоцикл изменён в другом окне. Загрузите актуальную версию.', 'current_revision' => (int) $record->revision], 409));
        }
    }
}
