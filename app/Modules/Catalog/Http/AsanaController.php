<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CatalogQuery;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Catalog\Services\Classification;
use App\Modules\Catalog\Services\DrawingSource;
use App\Modules\Catalog\Services\Facets;
use App\Modules\Catalog\Services\Names;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\NativeImages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AsanaController
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private CatalogReader $reader, private CollectionMembership $collections) {}

    public function index(Request $request): JsonResponse
    {
        $query = app(CatalogQuery::class);

        return response()->json($query->response($query->input($request)));
    }

    public function facetCounts(Request $request): JsonResponse
    {
        $query = app(CatalogQuery::class);
        $input = $query->input($request);

        return response()->json(['facets' => $query->counts($input), 'total' => $query->query($input)->count()]);
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
                    // An ordinary replacement has no editable drawing associated with it.
                    $asana->drawing_source = $data['drawing_source'] ?? null;
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

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'expected_revision' => ['required', 'integer', 'min:1']]);
        $source = $this->find($id);
        $original = $this->assets->originalData($source->current_image_asset_id);
        $bytes = base64_decode(substr($original['data_url'], strpos($original['data_url'], ',') + 1), true);
        if ($bytes === false) {
            throw new \RuntimeException('Не удалось прочитать изображение.');
        }
        $temp = tmpfile();
        if (! $temp) {
            throw new \RuntimeException('Не удалось подготовить изображение.');
        }
        try {
            fwrite($temp, $bytes);
            $asset = $this->assets->store(new UploadedFile(stream_get_meta_data($temp)['uri'], 'variant.png', null, null, true));
        } finally {
            fclose($temp);
        }
        try {
            $card = DB::transaction(function () use ($id, $input, $asset) {
                $this->collections->lockWorkspace();
                $limits = app(Entitlements::class);
                $limits->lock($this->workspace->id());
                $limits->assertCanCreate($this->workspace->id(), 'asanas', Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->count());
                $source = $this->find($id, true);
                $this->checkRevision($source, $input['expected_revision']);
                $card = $source->replicate(['id', 'import_key', 'import_source', 'created_at', 'updated_at', 'archived_at']);
                $card->setRelations([]);
                $card->forceFill(['name' => trim($input['name']), 'normalized_name' => Names::normalize($input['name']),
                    'normalized_search_text' => Names::normalize(implode(' ', [$input['name'], $source->sanskrit_cyrillic ?? '', $source->sanskrit_latin ?? '', ...$source->aliases->pluck('name')->all()])),
                    'current_image_asset_id' => $asset['id'], 'revision' => 1, 'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId()])->save();
                $card->aliases()->createMany($source->aliases->map->only(['name', 'normalized_name'])->all());
                $card->categories()->sync($source->categories->pluck('id')->all());
                DB::table('catalog_asana_relations')->insert(['asana_id' => $card->id, 'related_asana_id' => $source->id, 'kind' => 'variation']);
                if ($card->drawing_source !== null) {
                    app(NativeImages::class)->remember($asset['id'], $card->drawing_source);
                }
                $memberships = $this->collections->collectionIdsForAsanas([$id])[$id] ?? [];
                $this->collections->addAsana($card->id, $memberships ?: [$this->collections->starterCollection()]);

                return $card;
            });
        } catch (\Throwable $error) {
            $this->assets->discardUnattached($asset['id']);
            throw $error;
        }

        return $this->response($card, 201);
    }

    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate(['manifest' => ['required', 'array'], 'apply' => ['sometimes', 'boolean']]);

        return response()->json(app(Classification::class)->run($data['manifest'], (bool) ($data['apply'] ?? false)));
    }

    public function favorite(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['favorite' => ['required', 'boolean']]);
        $this->find($id);
        DB::table('catalog_asana_preferences')->upsert([
            ['workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'asana_id' => $id, 'favorite' => $data['favorite'], 'last_used_at' => null],
        ], ['workspace_id', 'user_id', 'asana_id'], ['favorite']);

        return response()->json(['favorite' => (bool) $data['favorite']]);
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
            if (! $archived && (! ($this->collections->collectionIdsForAsanas([$asana->id])[$asana->id] ?? []))) {
                throw ValidationException::withMessages(['asana' => 'Перед восстановлением укажите действующую подборку в карточке.']);
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
        if ($request->exists('drawing_source')) {
            $source = app(DrawingSource::class)->validate($request->input('drawing_source'));
            if ($source !== null && (! $request->hasFile('image') || $request->file('image')->getMimeType() !== 'image/png')) {
                throw ValidationException::withMessages(['drawing_source' => 'Сохранение исходника рисунка требует нового изображения PNG.']);
            }
            if ($source !== null) {
                $dimensions = @getimagesize($request->file('image')->getRealPath());
                if (! $dimensions || $dimensions[0] !== 1024 || $dimensions[1] !== 1024) {
                    throw ValidationException::withMessages(['image' => 'Изображение рисунка должно иметь размер 1024 × 1024.']);
                }
            }
            $request->merge(['drawing_source' => $source]);
        }
        foreach (['aliases', 'category_ids', 'collection_ids', 'relations', 'steps', 'teacher_cues', 'equipment', 'precautions', 'adaptations'] as $field) {
            if (is_string($request->input($field))) {
                $decoded = json_decode($request->input($field), true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! array_is_list($decoded)) {
                    throw ValidationException::withMessages([$field => 'Ожидается список значений.']);
                }
                $request->merge([$field => $decoded]);
            }
        }
        foreach (['facet_assignments', 'editorial'] as $field) {
            if (is_string($request->input($field))) {
                try {
                    $decoded = json_decode($request->input($field), true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw ValidationException::withMessages([$field => 'Ожидается объект JSON.']);
                }
                if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    throw ValidationException::withMessages([$field => 'Ожидается объект значений.']);
                }
                $request->merge([$field => $decoded]);
            }
        }
        $data = $request->validate([
            'sanskrit_cyrillic' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sanskrit_latin' => ['sometimes', 'nullable', 'string', 'max:160'],
            'steps' => ['sometimes', 'array', 'list', 'max:50'], 'steps.*' => ['required', 'string', 'max:2000'],
            'teacher_cues' => ['sometimes', 'array', 'list', 'max:30'], 'teacher_cues.*' => ['required', 'string', 'max:1000'],
            'equipment' => ['sometimes', 'array', 'list', 'max:20'], 'equipment.*' => ['required', 'string', 'max:80', 'distinct'],
            'precautions' => ['sometimes', 'array', 'list', 'max:30'], 'precautions.*' => ['required', 'string', 'max:2000'],
            'adaptations' => ['sometimes', 'array', 'list', 'max:30'], 'adaptations.*' => ['required', 'string', 'max:2000'],
            'editorial' => ['sometimes', 'array:author,source,reviewed,review_note'],
            'editorial.author' => ['nullable', 'string', 'max:160'], 'editorial.source' => ['nullable', 'string', 'max:2000'],
            'editorial.reviewed' => ['sometimes', 'boolean'], 'editorial.review_note' => ['nullable', 'string', 'max:2000'],
            'has_bind' => ['sometimes', 'nullable', 'boolean'],
            'classification_status' => ['sometimes', Rule::in(['unreviewed', 'partial', 'reviewed'])],
            'facet_assignments' => ['sometimes', 'array'],
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'aliases' => ['sometimes', 'array', 'list', 'max:500'], 'aliases.*' => ['required', 'string', 'max:160', 'distinct'],
            'category_ids' => ['sometimes', 'array', 'list', 'max:100'], 'category_ids.*' => ['uuid', 'distinct:ignore_case'],
            'collection_ids' => [$update ? 'sometimes' : 'required', 'array', 'list', 'min:1', 'max:100'],
            'collection_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'relations' => ['sometimes', 'array', 'list', 'max:60'],
            'relations.*.asana_id' => ['required', 'uuid'],
            'relations.*.kind' => ['required', Rule::in(['simpler', 'harder', 'preparation', 'follow_up', 'compensation', 'variation'])],
            'image' => [$update ? 'nullable' : 'required', 'file', 'max:'.config('ribka.upload_max_kb', 10240)],
            'drawing_source' => ['sometimes', 'nullable', 'array'],
            'expected_revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
        if (isset($data['category_ids']) && Category::where('workspace_id', $this->workspace->id())->whereNull('dimension')->whereIn('id', $data['category_ids'])->count() !== count($data['category_ids'])) {
            throw ValidationException::withMessages(['category_ids' => 'Одна из выбранных категорий недоступна.']);
        }
        if (isset($data['facet_assignments'])) {
            $data['facet_assignments'] = app(Facets::class)->validate($data['facet_assignments'], $this->workspace->id());
        }
        foreach ($data['relations'] ?? [] as $index => $relation) {
            $data['relations'][$index]['asana_id'] = strtolower($relation['asana_id']);
        }

        return $data;
    }

    private function apply(Asana $asana, array $data): void
    {
        if (isset($data['facet_assignments'])) {
            app(Facets::class)->validate($data['facet_assignments'], $this->workspace->id(), true);
        }
        $asana->name = $data['name'] ?? $asana->name;
        if (array_key_exists('drawing_source', $data)) {
            $asana->drawing_source = $data['drawing_source'];
        }
        if (array_key_exists('description', $data)) {
            $asana->description = $data['description'];
        }
        foreach (['sanskrit_cyrillic', 'sanskrit_latin', 'steps', 'teacher_cues', 'equipment', 'precautions', 'adaptations', 'editorial', 'has_bind', 'classification_status'] as $field) {
            if (array_key_exists($field, $data)) {
                $asana->{$field} = $data[$field];
            }
        }
        $aliases = $data['aliases'] ?? ($asana->exists ? $asana->aliases->pluck('name')->all() : []);
        $asana->normalized_name = Names::normalize($asana->name);
        $asana->normalized_search_text = Names::normalize(implode(' ', [$asana->name, $asana->sanskrit_cyrillic ?? '', $asana->sanskrit_latin ?? '', ...$aliases]));
        $asana->updated_by = $this->workspace->userId();
        $asana->save();
        if (! empty($data['drawing_source'])) {
            app(NativeImages::class)->remember($asana->current_image_asset_id, $asana->drawing_source);
        }
        if (array_key_exists('aliases', $data)) {
            $asana->aliases()->delete();
            $asana->aliases()->createMany(array_map(fn ($name) => ['name' => trim($name), 'normalized_name' => Names::normalize($name)], $aliases));
        }
        if (array_key_exists('category_ids', $data)) {
            app(Facets::class)->syncLegacy($asana, $data['category_ids']);
        }
        if (array_key_exists('facet_assignments', $data)) {
            app(Facets::class)->sync($asana, $data['facet_assignments']);
        }
        if ($asana->classification_status === 'reviewed') {
            foreach (Facets::DIMENSIONS as $dimension) {
                if (! $asana->categories()->where('dimension', $dimension)->exists()) {
                    $asana->forceFill(['classification_status' => 'partial'])->save();
                    break;
                }
            }
        }
        if (array_key_exists('relations', $data)) {
            $ids = array_values(array_unique(array_column($data['relations'], 'asana_id')));
            $targets = Asana::query()->select(['id', 'archived_at'])->where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->sharedLock()->get()->keyBy('id');
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
        return response()->json(['data' => $this->reader->serialize(new Collection([$asana]), true)[$asana->id]], $status);
    }
}
