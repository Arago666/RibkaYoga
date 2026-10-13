<?php

namespace App\Modules\Collections\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Collections\FolderTree;
use App\Modules\Collections\Models\AsanaCollection;
use App\Modules\Collections\PublicApi\CollectionMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CollectionController
{
    public function __construct(private WorkspaceContext $workspace, private AsanaReader $asanas, private FolderTree $folders, private CollectionMembership $membership) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'archive' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'folder_id' => ['nullable', 'string', 'max:40'], 'include_descendants' => ['nullable', 'boolean'],
        ]);
        $query = AsanaCollection::where('workspace_id', $this->workspace->id());
        match ($input['archive'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'), 'all' => null, default => $query->whereNull('archived_at'),
        };
        if (! empty($input['folder_id'])) {
            if ($input['folder_id'] === 'root') {
                $query->whereNull('folder_id');
            } else {
                validator(['folder_id' => $input['folder_id']], ['folder_id' => ['uuid']])->validate();
                $folder = $this->folders->requireFolder($input['folder_id']);
                $query->whereIn('folder_id', ($input['include_descendants'] ?? false) ? $this->folders->descendants($folder->id) : [$folder->id]);
            }
        }
        if (! empty($input['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_replace('ё', 'е', mb_strtolower($input['q']))).'%';
            $query->whereRaw("replace(lower(name), 'ё', 'е') like ?", [$term]);
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($input['per_page'] ?? 24);

        return response()->json([
            'data' => $this->serialize($page->getCollection()), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->response($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, false);
        $collection = DB::transaction(function () use ($data) {
            $this->folders->lock();
            $this->folders->requireFolder($data['folder_id'] ?? null);
            $this->asanas->findMany($data['asana_ids'] ?? []);
            $collection = AsanaCollection::create([
                'workspace_id' => $this->workspace->id(), 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'revision' => 1, 'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId(),
                'folder_id' => $data['folder_id'] ?? null,
            ]);
            $this->setItems($collection, $data['asana_ids'] ?? []);

            return $collection;
        });

        return $this->response($collection, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, true);
        $collection = DB::transaction(function () use ($id, $data) {
            $this->folders->lock();
            if (array_key_exists('folder_id', $data)) {
                $this->folders->requireFolder($data['folder_id']);
            }
            $collection = $this->find($id, true);
            $this->checkRevision($collection, $data['expected_revision']);
            if (array_key_exists('asana_ids', $data)) {
                $cards = $this->asanas->findMany($data['asana_ids'], true);
                $existing = $collection->items()->pluck('asana_id')->all();
                if (! $collection->archived_at) {
                    $this->membership->assertCanRemove($id, array_values(array_diff($existing, $data['asana_ids'])));
                }
                foreach ($cards as $card) {
                    if ($card['archived_at'] && ! in_array($card['id'], $existing, true)) {
                        throw ValidationException::withMessages(['asana_ids' => 'Сначала восстановите архивную асану, чтобы добавить её в подборку.']);
                    }
                }
                $this->setItems($collection, $data['asana_ids']);
            }
            $collection->forceFill([
                'name' => $data['name'] ?? $collection->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $collection->description,
                'folder_id' => array_key_exists('folder_id', $data) ? $data['folder_id'] : $collection->folder_id,
                'revision' => $collection->revision + 1, 'updated_by' => $this->workspace->userId(),
            ])->save();

            return $collection;
        });

        return $this->response($collection);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->setArchived($request, $id, true);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->setArchived($request, $id, false);
    }

    private function setArchived(Request $request, string $id, bool $archived): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $collection = DB::transaction(function () use ($id, $data, $archived) {
            $this->folders->lock();
            $collection = $this->find($id, true);
            $this->checkRevision($collection, $data['expected_revision']);
            if ($archived && ! $collection->archived_at) {
                $this->membership->assertCanRemove($id, $collection->items()->pluck('asana_id')->all());
            }
            $collection->forceFill(['archived_at' => $archived ? now() : null, 'revision' => $collection->revision + 1, 'updated_by' => $this->workspace->userId()])->save();

            return $collection;
        });

        return $this->response($collection);
    }

    private function validate(Request $request, bool $update): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'asana_ids' => ['sometimes', 'array', 'list', 'max:500'], 'asana_ids.*' => ['uuid', 'distinct:ignore_case'],
            'expected_revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
    }

    private function setItems(AsanaCollection $collection, array $ids): void
    {
        $collection->items()->delete();
        if ($ids) {
            DB::table('asana_collection_items')->insert(array_map(fn ($id, $position) => [
                'collection_id' => $collection->id, 'asana_id' => $id, 'position' => $position,
            ], $ids, array_keys($ids)));
        }
        $collection->unsetRelation('items');
    }

    private function find(string $id, bool $lock = false): AsanaCollection
    {
        $query = AsanaCollection::where('workspace_id', $this->workspace->id());

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    private function checkRevision(AsanaCollection $collection, int $revision): void
    {
        abort_unless($collection->revision === $revision, 409, 'Подборка изменена в другом окне. Обновите её перед сохранением.');
    }

    private function serialize(Collection $collections): array
    {
        $collections->loadMissing('items');
        $ids = $collections->flatMap(fn ($collection) => $collection->items->pluck('asana_id'))->unique()->values()->all();
        $asanas = $this->asanas->findMany($ids, true);
        $folders = $this->folders->folders();

        return $collections->map(fn ($collection) => [
            'id' => $collection->id, 'name' => $collection->name, 'description' => $collection->description,
            'folder_id' => $collection->folder_id, 'folder_path' => $this->folders->path($collection->folder_id, $folders),
            'revision' => $collection->revision, 'archived_at' => $collection->archived_at?->toISOString(),
            'updated_at' => $collection->updated_at?->toISOString(),
            'asana_ids' => $collection->items->pluck('asana_id')->all(),
            'items' => $collection->items->map(fn ($item) => $asanas[$item->asana_id])->all(),
        ])->all();
    }

    private function response(AsanaCollection $collection, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->serialize(new Collection([$collection]))[0]], $status);
    }
}
