<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogReader implements AsanaReader
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets) {}

    public function findMany(array $ids, bool $includeArchived = false): array
    {
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw ValidationException::withMessages(['asana_ids' => 'Некорректная ссылка на асану.']);
            }
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        $query = Asana::where('workspace_id', $this->workspace->id())->whereIn('id', $ids);
        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }
        // Snapshot creation and collection updates must see a card that stays
        // active until their surrounding transaction has committed.
        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $asanas = $query->orderBy('id')->get();
        if ($asanas->count() !== count($ids)) {
            throw ValidationException::withMessages(['asana_ids' => 'Одна из выбранных асан недоступна или находится в архиве.']);
        }

        return $this->serialize($asanas);
    }

    public function serialize(Collection $asanas): array
    {
        if ($asanas->isEmpty()) {
            return [];
        }
        $asanas->loadMissing(['aliases', 'categories']);
        $images = $this->assets->getMany($asanas->pluck('current_image_asset_id')->unique()->all());
        $memberships = app(CollectionMembership::class)->collectionIdsForAsanas($asanas->modelKeys());
        $links = DB::table('catalog_asana_relations')->whereIn('asana_id', $asanas->modelKeys())->get();
        $related = Asana::where('workspace_id', $this->workspace->id())->whereIn('id', $links->pluck('related_asana_id')->unique())->get()->keyBy('id');
        $relatedImages = $this->assets->getMany($related->pluck('current_image_asset_id')->unique()->all());

        return $asanas->mapWithKeys(fn (Asana $asana) => [$asana->id => [
            'id' => $asana->id, 'name' => $asana->name, 'description' => $asana->description,
            'aliases' => $asana->aliases->pluck('name')->all(),
            'collection_ids' => $memberships[$asana->id] ?? [],
            'relations' => $links->where('asana_id', $asana->id)->filter(fn ($link) => isset($related[$link->related_asana_id]))->map(fn ($link) => [
                'kind' => $link->kind, 'asana_id' => $link->related_asana_id,
                'name' => $related[$link->related_asana_id]->name,
                'image_url' => $relatedImages[$related[$link->related_asana_id]->current_image_asset_id]['thumbnail_url'],
                'archived_at' => $related[$link->related_asana_id]->archived_at?->toISOString(),
            ])->values()->all(),
            'categories' => $asana->categories->map->only(['id', 'name', 'revision'])->all(),
            'image_asset_id' => $asana->current_image_asset_id,
            'image_url' => $images[$asana->current_image_asset_id]['image_url'],
            'thumbnail_url' => $images[$asana->current_image_asset_id]['thumbnail_url'],
            'revision' => $asana->revision, 'archived_at' => $asana->archived_at?->toISOString(),
            'created_at' => $asana->created_at?->toISOString(), 'updated_at' => $asana->updated_at?->toISOString(),
        ]])->all();
    }
}
