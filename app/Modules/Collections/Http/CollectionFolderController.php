<?php

namespace App\Modules\Collections\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Collections\FolderTree;
use App\Modules\Collections\Models\AsanaCollection;
use App\Modules\Collections\Models\CollectionFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionFolderController
{
    public function __construct(private WorkspaceContext $workspace, private FolderTree $tree) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->tree->serialize(), 'max_depth' => FolderTree::MAX_DEPTH]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, false);
        $id = DB::transaction(function () use ($data) {
            $this->tree->lock();
            $parentId = $data['parent_id'] ?? null;
            $name = trim($data['name']);
            $this->tree->validateParent($parentId);
            $this->tree->validateName($name, $parentId);

            return CollectionFolder::create([
                'workspace_id' => $this->workspace->id(), 'parent_id' => $parentId, 'name' => $name,
                'revision' => 1, 'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId(),
            ])->id;
        });

        return response()->json(['data' => collect($this->tree->serialize())->firstWhere('id', $id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, true);
        DB::transaction(function () use ($id, $data) {
            $this->tree->lock();
            $folder = $this->find($id);
            $this->checkRevision($folder, $data['expected_revision']);
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $folder->parent_id;
            $name = trim($data['name'] ?? $folder->name);
            $this->tree->validateParent($parentId, $folder);
            $this->tree->validateName($name, $parentId, $id);
            $folder->forceFill([
                'name' => $name, 'parent_id' => $parentId, 'revision' => $folder->revision + 1,
                'updated_by' => $this->workspace->userId(),
            ])->save();
        });

        return response()->json(['data' => collect($this->tree->serialize())->firstWhere('id', $id)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($id, $data) {
            $this->tree->lock();
            $folder = $this->find($id);
            $this->checkRevision($folder, $data['expected_revision']);
            if (CollectionFolder::where('workspace_id', $this->workspace->id())->where('parent_id', $id)->exists()
                || AsanaCollection::where('workspace_id', $this->workspace->id())->where('folder_id', $id)->exists()) {
                throw ValidationException::withMessages(['folder' => 'Сначала переместите подборки и подпапки. Удалить можно только пустую папку; архивные подборки тоже учитываются.']);
            }
            $folder->delete();
        });

        return response()->json(['data' => null]);
    }

    private function find(string $id): CollectionFolder
    {
        return CollectionFolder::where('workspace_id', $this->workspace->id())->lockForUpdate()->findOrFail($id);
    }

    private function checkRevision(CollectionFolder $folder, int $revision): void
    {
        abort_unless($folder->revision === $revision, 409, 'Папка изменена в другом окне. Обновите дерево папок.');
    }

    private function validate(Request $request, bool $update): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'expected_revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
    }
}
