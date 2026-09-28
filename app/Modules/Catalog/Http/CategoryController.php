<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::where('workspace_id', $this->workspace->id())
            ->orderBy('name')->orderBy('id')->get()->map->only(['id', 'name', 'revision'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, false);
        $category = DB::transaction(function () use ($data) {
            unset($data['expected_revision']);

            return Category::create([...$data, 'workspace_id' => $this->workspace->id(), 'parent_id' => null, 'revision' => 1]);
        }, 3);

        return response()->json(['data' => $category->only(['id', 'name', 'revision'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, true);
        $category = DB::transaction(function () use ($data, $id) {
            $category = Category::where('workspace_id', $this->workspace->id())->lockForUpdate()->findOrFail($id);
            $this->checkRevision($category, $data['expected_revision']);
            unset($data['expected_revision']);
            $category->fill([...$data, 'parent_id' => null, 'revision' => $category->revision + 1])->save();

            return $category;
        }, 3);

        return response()->json(['data' => $category->only(['id', 'name', 'revision'])]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($id, $data) {
            $category = Category::where('workspace_id', $this->workspace->id())->lockForUpdate()->findOrFail($id);
            $this->checkRevision($category, $data['expected_revision']);
            if (DB::table('catalog_asana_category')->where('category_id', $id)->exists()) {
                throw ValidationException::withMessages(['category' => 'Сначала снимите эту категорию с асан.']);
            }
            $category->delete();
        }, 3);

        return response()->json(['message' => 'Категория удалена.']);
    }

    private function validate(Request $request, bool $update): array
    {
        if ($request->filled('parent_id')) {
            throw ValidationException::withMessages(['parent_id' => 'Подкатегории больше не поддерживаются. Используйте обычную категорию.']);
        }

        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'expected_revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
    }

    private function checkRevision(Category $category, int $revision): void
    {
        abort_unless($category->revision === $revision, 409, 'Категория уже изменена. Обновите список.');
    }
}
