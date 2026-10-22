<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PracticeFolderController
{
    public function __construct(private WorkspaceContext $workspace) {}

    private function folders()
    {
        return DB::table('practice_personal_folders')->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->folders();
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        $items = $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $counts = DB::table('practice_document_folders')->whereIn('folder_id', $items->pluck('id'))->select('folder_id')->selectRaw('count(*) as count')->groupBy('folder_id')->pluck('count', 'folder_id');

        return response()->json(['data' => $items->map(fn ($item) => (array) $item + ['documents_count' => (int) ($counts[$item->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:160']);
        $id = (string) Str::uuid();
        $this->folders()->insert(['id' => $id, 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'name' => trim($input['name']), 'sort_order' => (int) $this->folders()->max('sort_order') + 1, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->folders()->where('id', $id)->first()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'name' => 'sometimes|required|string|max:160', 'sort_order' => 'sometimes|integer|min:0|max:100000', 'archived' => 'sometimes|boolean']);
        $record = DB::transaction(function () use ($input, $id) {
            $folder = $this->folders()->where('id', $id)->lockForUpdate()->first();
            abort_unless($folder, 404);
            abort_unless((int) $folder->revision === (int) $input['expected_revision'], 409, 'Категория изменена в другом окне. Обновите список.');
            $values = ['revision' => $folder->revision + 1, 'updated_at' => now()];
            if (isset($input['name'])) {
                $values['name'] = trim($input['name']);
            }
            if (isset($input['sort_order'])) {
                $values['sort_order'] = $input['sort_order'];
            }
            if (array_key_exists('archived', $input)) {
                $values['archived_at'] = $input['archived'] ? now() : null;
            }
            $this->folders()->where('id', $id)->update($values);

            return $this->folders()->where('id', $id)->first();
        });

        return response()->json(['data' => $record]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'documents' => 'required|array|list|min:1|max:100', 'documents.*.id' => 'required|uuid|distinct', 'documents.*.expected_revision' => 'required|integer|min:1', 'remove' => 'sometimes|boolean']);
        DB::transaction(function () use ($id, $input) {
            $folder = $this->folders()->where('id', $id)->whereNull('archived_at')->lockForUpdate()->first();
            abort_unless($folder, 404);
            abort_unless((int) $folder->revision === (int) $input['expected_revision'], 409, 'Категория изменена. Обновите список.');
            $ids = array_column($input['documents'], 'id');
            $documents = DB::table('practice_documents')->where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($documents->count() === count($ids), 404);
            foreach ($input['documents'] as $document) {
                abort_unless((int) $documents[$document['id']]->revision === (int) $document['expected_revision'], 409, 'Практика изменилась. Обновите список.');
                if ($input['remove'] ?? false) {
                    DB::table('practice_document_folders')->where('folder_id', $id)->where('document_id', $document['id'])->delete();
                } else {
                    DB::table('practice_document_folders')->insertOrIgnore(['folder_id' => $id, 'document_id' => $document['id']]);
                }
            }
            $this->folders()->where('id', $id)->update(['revision' => $folder->revision + 1, 'updated_at' => now()]);
        });

        return response()->json(['data' => $this->folders()->where('id', $id)->first()]);
    }
}
