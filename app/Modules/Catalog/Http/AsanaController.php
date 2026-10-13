<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Catalog\Services\Names;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\RecentAsanas;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AsanaController
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private CatalogReader $reader, private CollectionMembership $collections) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'category' => ['nullable', 'uuid'],
            'archive' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'sort' => ['nullable', Rule::in(['name_asc', 'name_desc', 'recent', 'used_recently'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:96'],
        ]);
        $query = Asana::where('workspace_id', $this->workspace->id());
        match ($input['archive'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
        if (! empty($input['q'])) {
            $query->where('normalized_search_text', 'like', Names::like($input['q']));
        }
        if (! empty($input['category'])) {
            $category = Category::where('workspace_id', $this->workspace->id())->findOrFail($input['category']);
            $query->whereHas('categories', fn ($categories) => $categories->where('catalog_categories.id', $category->id));
        }
        $sort = $input['sort'] ?? 'name_asc';
        if ($sort === 'used_recently') {
            $ids = app(RecentAsanas::class)->ids();
            $query->whereIn('id', $ids);
            if ($ids) {
                $cases = implode(' ', array_map(fn ($i) => 'WHEN ? THEN '.$i, array_keys($ids)));
                $query->orderByRaw('CASE id '.$cases.' ELSE '.count($ids).' END', $ids);
            }
        } elseif ($sort === 'recent') {
            $query->orderByDesc('created_at');
        } else {
            $direction = $sort === 'name_desc' ? 'desc' : 'asc';
            if (DB::getDriverName() === 'pgsql') {
                $query->orderByRaw('normalized_name COLLATE ribka_ru '.$direction);
            } else {
                $query->orderBy('normalized_name', $direction);
            }
        }
        $page = $query->orderBy('id')->paginate($input['per_page'] ?? 48);

        return response()->json([
            'data' => array_values($this->reader->serialize($page->getCollection())),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->response($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, false);
        $asset = $this->assets->store($request->file('image'));
        try {
            $asana = DB::transaction(function () use ($data, $asset) {
                $this->collections->lockWorkspace();
                $limits = app(Entitlements::class);
                $limits->lock($this->workspace->id());
                $limits->assertCanCreate($this->workspace->id(), 'asanas', Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->count());
                $asana = new Asana([
                    'workspace_id' => $this->workspace->id(), 'created_by' => $this->workspace->userId(),
                    'updated_by' => $this->workspace->userId(), 'current_image_asset_id' => $asset['id'], 'revision' => 1,
                ]);
                $this->apply($asana, $data);
                $this->collections->addAsana($asana->id, $data['collection_ids'] ?? []);

                return $asana;
            });
        } catch (\Throwable $error) {
            $this->assets->discardUnattached($asset['id']);
            throw $error;
        }

        return $this->response($asana, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->find($id);
        $data = $this->validate($request, true);
        $asset = $request->hasFile('image') ? $this->assets->store($request->file('image')) : null;
        try {
            $asana = DB::transaction(function () use ($id, $data, $asset) {
                $this->collections->lockWorkspace();
                $asana = $this->find($id, true);
                $this->checkRevision($asana, $data['expected_revision']);
                if ($asset) {
                    $asana->current_image_asset_id = $asset['id'];
                }
                $asana->revision++;
                $this->apply($asana, $data);
                if (array_key_exists('collection_ids', $data)) {
                    $this->collections->syncAsana($asana->id, $data['collection_ids']);
                } elseif (! ($this->collections->collectionIdsForAsanas([$asana->id])[$asana->id] ?? [])) {
                    throw ValidationException::withMessages(['collection_ids' => 'Выберите хотя бы одну действующую подборку.']);
                }

                return $asana;
            });
        } catch (\Throwable $error) {
            if ($asset) {
                $this->assets->discardUnattached($asset['id']);
            }
            throw $error;
        }

        return $this->response($asana);
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
        $asana = DB::transaction(function () use ($id, $data, $archived) {
            $this->collections->lockWorkspace();
            $limits = app(Entitlements::class);
            if (! $archived) {
                $limits->lock($this->workspace->id());
            }
            $asana = $this->find($id, true);
            $this->checkRevision($asana, $data['expected_revision']);
            if (! $archived && (! $asana->categories()->exists() || ! ($this->collections->collectionIdsForAsanas([$asana->id])[$asana->id] ?? []))) {
                throw ValidationException::withMessages(['asana' => 'Перед восстановлением укажите категорию и действующую подборку в карточке.']);
            }
            if (! $archived && $asana->archived_at) {
                $limits->assertCanCreate($this->workspace->id(), 'asanas', Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->count());
            }
            $asana->forceFill(['archived_at' => $archived ? now() : null, 'revision' => $asana->revision + 1, 'updated_by' => $this->workspace->userId()])->save();

            return $asana;
        });

        return $this->response($asana);
    }

    private function validate(Request $request, bool $update): array
    {
        foreach (['aliases', 'category_ids', 'collection_ids', 'relations'] as $field) {
            if (is_string($request->input($field))) {
                $decoded = json_decode($request->input($field), true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! array_is_list($decoded)) {
                    throw ValidationException::withMessages([$field => 'Ожидается список значений.']);
                }
                $request->merge([$field => $decoded]);
            }
        }
        $data = $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'aliases' => ['sometimes', 'array', 'list', 'max:20'], 'aliases.*' => ['required', 'string', 'max:160', 'distinct'],
            'category_ids' => [$update ? 'sometimes' : 'required', 'array', 'list', 'min:1', 'max:30'], 'category_ids.*' => ['uuid', 'distinct:ignore_case'],
            'collection_ids' => [$update ? 'sometimes' : 'required', 'array', 'list', 'min:1', 'max:100'],
            'collection_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'relations' => ['sometimes', 'array', 'list', 'max:60'],
            'relations.*.asana_id' => ['required', 'uuid'],
            'relations.*.kind' => ['required', Rule::in(['simpler', 'harder', 'preparation'])],
            'image' => [$update ? 'nullable' : 'required', 'file', 'max:'.config('ribka.upload_max_kb', 10240)],
            'expected_revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
        if (isset($data['category_ids']) && Category::where('workspace_id', $this->workspace->id())->whereIn('id', $data['category_ids'])->count() !== count($data['category_ids'])) {
            throw ValidationException::withMessages(['category_ids' => 'Одна из выбранных категорий недоступна.']);
        }
        foreach ($data['relations'] ?? [] as $index => $relation) {
            $data['relations'][$index]['asana_id'] = strtolower($relation['asana_id']);
        }

        return $data;
    }

    private function apply(Asana $asana, array $data): void
    {
        // Recheck categories within the write transaction in case one was
        // removed after request validation completed.
        if (isset($data['category_ids'])) {
            $available = Category::where('workspace_id', $this->workspace->id())
                ->whereIn('id', $data['category_ids'])->orderBy('id')->sharedLock()->get(['id']);
            if ($available->count() !== count($data['category_ids'])) {
                throw ValidationException::withMessages(['category_ids' => 'Категория уже удалена. Обновите список.']);
            }
        }
        $asana->name = $data['name'] ?? $asana->name;
        if (array_key_exists('description', $data)) {
            $asana->description = $data['description'];
        }
        $aliases = $data['aliases'] ?? ($asana->exists ? $asana->aliases->pluck('name')->all() : []);
        $asana->normalized_name = Names::normalize($asana->name);
        $asana->normalized_search_text = Names::normalize(implode(' ', [$asana->name, ...$aliases]));
        $asana->updated_by = $this->workspace->userId();
        $asana->save();
        if (array_key_exists('aliases', $data)) {
            $asana->aliases()->delete();
            $asana->aliases()->createMany(array_map(fn ($name) => ['name' => trim($name), 'normalized_name' => Names::normalize($name)], $aliases));
        }
        if (array_key_exists('category_ids', $data)) {
            $asana->categories()->sync($data['category_ids']);
        }
        if (! $asana->categories()->exists()) {
            throw ValidationException::withMessages(['category_ids' => 'Выберите хотя бы одну категорию.']);
        }
        if (array_key_exists('relations', $data)) {
            $ids = array_values(array_unique(array_column($data['relations'], 'asana_id')));
            $targets = Asana::where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->sharedLock()->get()->keyBy('id');
            $previous = DB::table('catalog_asana_relations')->where('asana_id', $asana->id)->get()->keyBy(fn ($relation) => $relation->related_asana_id.':'.$relation->kind);
            if (in_array($asana->id, $ids, true) || $targets->count() !== count($ids)) {
                throw ValidationException::withMessages(['relations' => 'Связанный вариант недоступен или совпадает с этой асаной.']);
            }
            foreach ($data['relations'] as $relation) {
                if ($targets[$relation['asana_id']]->archived_at && ! $previous->has($relation['asana_id'].':'.$relation['kind'])) {
                    throw ValidationException::withMessages(['relations' => 'Новый связанный вариант должен быть действующей карточкой.']);
                }
            }
            DB::table('catalog_asana_relations')->where('asana_id', $asana->id)->delete();
            $rows = [];
            foreach ($data['relations'] as $relation) {
                $rows[$relation['asana_id'].':'.$relation['kind']] = ['asana_id' => $asana->id, 'related_asana_id' => $relation['asana_id'], 'kind' => $relation['kind']];
            }
            if ($rows) {
                DB::table('catalog_asana_relations')->insert(array_values($rows));
            }
        }
        $asana->unsetRelations();
    }

    private function find(string $id, bool $lock = false): Asana
    {
        $query = Asana::where('workspace_id', $this->workspace->id());

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    private function checkRevision(Asana $asana, int $revision): void
    {
        abort_unless($asana->revision === $revision, 409, 'Карточка изменена в другом окне. Обновите данные перед сохранением.');
    }

    private function response(Asana $asana, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->reader->serialize(new Collection([$asana]))[$asana->id]], $status);
    }
}
