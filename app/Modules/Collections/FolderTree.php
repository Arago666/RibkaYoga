<?php

namespace App\Modules\Collections;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Collections\Models\AsanaCollection;
use App\Modules\Collections\Models\CollectionFolder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FolderTree
{
    public const MAX_DEPTH = 8;

    public function __construct(private WorkspaceContext $workspace) {}

    /** Call inside the transaction, before locking a collection or folder. */
    public function lock(): void
    {
        $workspaceId = $this->workspace->id();
        DB::table('collection_folder_trees')->insertOrIgnore(['workspace_id' => $workspaceId]);
        DB::table('collection_folder_trees')->where('workspace_id', $workspaceId)->lockForUpdate()->first();
    }

    public function folders(): Collection
    {
        return CollectionFolder::where('workspace_id', $this->workspace->id())->orderBy('name')->orderBy('id')->get()->keyBy('id');
    }

    public function requireFolder(?string $id): ?CollectionFolder
    {
        if ($id === null) {
            return null;
        }
        $folder = CollectionFolder::where('workspace_id', $this->workspace->id())->find($id);
        if (! $folder) {
            throw ValidationException::withMessages(['folder_id' => 'Папка недоступна. Обновите список папок.']);
        }

        return $folder;
    }

    public function descendants(string $id, ?Collection $folders = null): array
    {
        $folders ??= $this->folders();
        $ids = [$id];
        for ($index = 0; $index < count($ids); $index++) {
            foreach ($folders as $folder) {
                if ($folder->parent_id === $ids[$index] && ! in_array($folder->id, $ids, true)) {
                    $ids[] = $folder->id;
                }
            }
        }

        return $ids;
    }

    public function path(?string $id, ?Collection $folders = null): ?string
    {
        if ($id === null) {
            return null;
        }
        $folders ??= $this->folders();
        $parts = [];
        $seen = [];
        while ($id && isset($folders[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $parts[] = $folders[$id]->name;
            $id = $folders[$id]->parent_id;
        }

        return implode(' / ', array_reverse($parts));
    }

    public function validateParent(?string $parentId, ?CollectionFolder $folder = null): void
    {
        $folders = $this->folders();
        if ($parentId !== null && ! isset($folders[$parentId])) {
            throw ValidationException::withMessages(['parent_id' => 'Родительская папка недоступна.']);
        }
        $subtree = $folder ? $this->descendants($folder->id, $folders) : [];
        if ($parentId && in_array($parentId, $subtree, true)) {
            throw ValidationException::withMessages(['parent_id' => 'Нельзя переместить папку внутрь неё самой или её подпапки.']);
        }
        // Count actual parents rather than name separators.
        $parentDepth = 0;
        for ($cursor = $parentId; $cursor; $cursor = $folders[$cursor]->parent_id) {
            $parentDepth++;
        }
        $height = 1;
        if ($folder) {
            foreach ($subtree as $id) {
                $depth = 1;
                for ($cursor = $id; $cursor !== $folder->id; $cursor = $folders[$cursor]->parent_id) {
                    $depth++;
                }
                $height = max($height, $depth);
            }
        }
        if ($parentDepth + $height > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_id' => 'Допускается не более '.self::MAX_DEPTH.' уровней папок.']);
        }
    }

    public function validateName(string $name, ?string $parentId, ?string $exceptId = null): void
    {
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Введите название папки.']);
        }
        $query = CollectionFolder::where('workspace_id', $this->workspace->id())->where('parent_id', $parentId);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $normalized = mb_strtolower(trim($name));
        if ($query->get()->contains(fn ($folder) => mb_strtolower($folder->name) === $normalized)) {
            throw ValidationException::withMessages(['name' => 'В этой папке уже есть папка с таким названием.']);
        }
    }

    public function serialize(): array
    {
        $folders = $this->folders();
        $counts = AsanaCollection::where('workspace_id', $this->workspace->id())->whereNotNull('folder_id')
            ->selectRaw('folder_id, count(*) as total, sum(case when archived_at is null then 1 else 0 end) as active')
            ->groupBy('folder_id')->get()->keyBy('folder_id');

        return $folders->map(fn ($folder) => [
            'id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id,
            'revision' => $folder->revision, 'path' => $this->path($folder->id, $folders),
            'collections_count' => (int) ($counts[$folder->id]->active ?? 0),
            'total_collections_count' => (int) ($counts[$folder->id]->total ?? 0),
        ])->values()->all();
    }
}
