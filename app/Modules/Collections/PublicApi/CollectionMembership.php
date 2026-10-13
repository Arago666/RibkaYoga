<?php

namespace App\Modules\Collections\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Collections\FolderTree;
use App\Modules\Collections\Models\AsanaCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionMembership
{
    public function __construct(private WorkspaceContext $workspace, private AsanaReader $asanas, private FolderTree $tree) {}

    /** Acquire inside the transaction before locking a card when its membership or active state changes. */
    public function lockWorkspace(): void
    {
        $this->tree->lock();
    }

    /** The starter catalog has a usable active collection before its first card is created. */
    public function starterCollection(): string
    {
        return DB::transaction(function () {
            $this->tree->lock();
            $collection = AsanaCollection::where('workspace_id', $this->workspace->id())->whereNull('archived_at')
                ->where('name', 'Базовая библиотека')->orderBy('id')->lockForUpdate()->first();
            if (! $collection) {
                $collection = AsanaCollection::create([
                    'workspace_id' => $this->workspace->id(), 'name' => 'Базовая библиотека',
                    'description' => null, 'folder_id' => null, 'revision' => 1,
                    'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId(),
                ]);
            }

            return $collection->id;
        });
    }

    /** Append without overwriting existing items; lock in stable order for parallel uploads. */
    public function addAsana(string $asanaId, array $collectionIds): void
    {
        if (! $collectionIds) {
            return;
        }
        DB::transaction(function () use ($asanaId, $collectionIds) {
            $this->tree->lock();
            $this->asanas->findMany([$asanaId]);
            $collections = AsanaCollection::where('workspace_id', $this->workspace->id())
                ->whereNull('archived_at')->whereIn('id', $collectionIds)->orderBy('id')->lockForUpdate()->get();
            if ($collections->count() !== count($collectionIds)) {
                throw ValidationException::withMessages(['collection_ids' => 'Одна из выбранных подборок недоступна. Обновите список подборок.']);
            }
            foreach ($collections as $collection) {
                if ($collection->items()->where('asana_id', $asanaId)->exists()) {
                    continue;
                }
                if ($collection->items()->count() >= 500) {
                    throw ValidationException::withMessages(['collection_ids' => 'В выбранной подборке уже 500 карточек. Выберите другую подборку.']);
                }
                $position = $collection->items()->max('position');
                $collection->items()->create(['asana_id' => $asanaId, 'position' => $position === null ? 0 : $position + 1]);
                $collection->forceFill(['revision' => $collection->revision + 1, 'updated_by' => $this->workspace->userId()])->save();
            }
        });
    }

    /** Active membership for each requested card, including empty lists for legacy cards. */
    public function collectionIdsForAsanas(array $asanaIds): array
    {
        $result = array_fill_keys($asanaIds, []);
        if (! $asanaIds) {
            return $result;
        }
        $items = DB::table('asana_collection_items as items')->join('asana_collections as collections', 'collections.id', '=', 'items.collection_id')
            ->where('collections.workspace_id', $this->workspace->id())->whereNull('collections.archived_at')
            ->whereIn('items.asana_id', $asanaIds)->orderBy('collections.name')->orderBy('collections.id')
            ->get(['items.asana_id', 'items.collection_id']);
        foreach ($items as $item) {
            $result[$item->asana_id][] = $item->collection_id;
        }

        return $result;
    }

    /** Replace active memberships atomically; historical archived collections keep their contents. */
    public function syncAsana(string $asanaId, array $collectionIds): void
    {
        if (! $collectionIds) {
            throw ValidationException::withMessages(['collection_ids' => 'Выберите хотя бы одну активную подборку для асаны.']);
        }
        if (count(array_unique($collectionIds)) !== count($collectionIds)) {
            throw ValidationException::withMessages(['collection_ids' => 'Подборки не должны повторяться.']);
        }
        DB::transaction(function () use ($asanaId, $collectionIds) {
            $this->tree->lock();
            $this->asanas->findMany([$asanaId], true);
            $collections = AsanaCollection::where('workspace_id', $this->workspace->id())->whereNull('archived_at')
                ->where(fn ($query) => $query->whereIn('id', $collectionIds)->orWhereHas('items', fn ($items) => $items->where('asana_id', $asanaId)))
                ->orderBy('id')->lockForUpdate()->get();
            $selected = $collections->whereIn('id', $collectionIds);
            if ($selected->count() !== count($collectionIds)) {
                throw ValidationException::withMessages(['collection_ids' => 'Одна из выбранных подборок недоступна. Обновите список подборок.']);
            }
            foreach ($collections as $collection) {
                $hasCard = $collection->items()->where('asana_id', $asanaId)->exists();
                $keepCard = in_array($collection->id, $collectionIds, true);
                if ($hasCard === $keepCard) {
                    continue;
                }
                if ($keepCard) {
                    if ($collection->items()->count() >= 500) {
                        throw ValidationException::withMessages(['collection_ids' => 'В выбранной подборке уже 500 карточек. Выберите другую подборку.']);
                    }
                    $position = $collection->items()->max('position');
                    $collection->items()->create(['asana_id' => $asanaId, 'position' => $position === null ? 0 : $position + 1]);
                } else {
                    $collection->items()->where('asana_id', $asanaId)->delete();
                }
                $collection->forceFill(['revision' => $collection->revision + 1, 'updated_by' => $this->workspace->userId()])->save();
            }
        });
    }

    /** Caller holds the workspace collection lock before removing items or archiving a collection. */
    public function assertCanRemove(string $collectionId, array $asanaIds): void
    {
        if (! $asanaIds) {
            return;
        }
        $cards = $this->asanas->findMany($asanaIds, true);
        $remaining = DB::table('asana_collection_items as items')->join('asana_collections as collections', 'collections.id', '=', 'items.collection_id')
            ->where('collections.workspace_id', $this->workspace->id())->whereNull('collections.archived_at')
            ->where('collections.id', '!=', $collectionId)->whereIn('items.asana_id', $asanaIds)->pluck('items.asana_id')->all();
        $missing = array_filter($cards, fn ($card) => ! $card['archived_at'] && ! in_array($card['id'], $remaining, true));
        if ($missing) {
            $names = implode(', ', array_slice(array_column($missing, 'name'), 0, 3));
            throw ValidationException::withMessages([
                'asana_ids' => 'Асана должна оставаться хотя бы в одной активной подборке. Сначала добавьте в другую подборку: '.$names.'.',
            ]);
        }
    }
}
