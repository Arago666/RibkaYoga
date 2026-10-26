<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProgramFolderController
{
    public function __construct(private WorkspaceContext $workspace) {}

    private function folders()
    {
        return DB::table('practice_program_folders')->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->folders();
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        $folders = $query->orderBy('name')->orderBy('id')->get();
        $counts = [];
        foreach (['document', 'cycle'] as $kind) {
            $counts[$kind] = DB::table('practice_program_folder_'.$kind.'s')->whereIn('folder_id', $folders->pluck('id'))->select('folder_id')->selectRaw('count(*) as count')->groupBy('folder_id')->pluck('count', 'folder_id');
        }

        return response()->json(['data' => $folders->map(fn ($folder) => (array) $folder + ['items_count' => (int) ($counts['document'][$folder->id] ?? 0) + (int) ($counts['cycle'][$folder->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:160']);
        $id = (string) Str::uuid();
        $this->folders()->insert(['id' => $id, 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'name' => trim($input['name']), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->folders()->where('id', $id)->first()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'name' => 'sometimes|required|string|max:160', 'archived' => 'sometimes|boolean']);
        DB::transaction(function () use ($input, $id) {
            $folder = $this->folders()->where('id', $id)->lockForUpdate()->first();
            abort_unless($folder, 404);
            abort_unless((int) $folder->revision === (int) $input['expected_revision'], 409, 'Папка изменена в другом окне. Обновите список.');
            $values = ['revision' => $folder->revision + 1, 'updated_at' => now()];
            if (isset($input['name'])) {
                $values['name'] = trim($input['name']);
            }
            if (array_key_exists('archived', $input)) {
                $values['archived_at'] = $input['archived'] ? now() : null;
            }
            $this->folders()->where('id', $id)->update($values);
        });

        return response()->json(['data' => $this->folders()->where('id', $id)->first()]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'items' => 'required|array|list|min:1|max:100', 'items.*.id' => 'required|uuid|distinct', 'items.*.type' => 'required|in:practice,cycle', 'items.*.expected_revision' => 'required|integer|min:1', 'remove' => 'sometimes|boolean']);
        DB::transaction(function () use ($id, $input) {
            $folder = $this->folders()->where('id', $id)->whereNull('archived_at')->lockForUpdate()->first();
            abort_unless($folder, 404);
            abort_unless((int) $folder->revision === (int) $input['expected_revision'], 409, 'Папка изменена. Обновите список.');
            $items = $input['items'];
            usort($items, fn ($a, $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);
            foreach ($items as $item) {
                $kind = $item['type'] === 'cycle' ? 'cycle' : 'document';
                $table = $kind === 'cycle' ? 'practice_mesocycles' : 'practice_documents';
                $record = DB::table($table)->where('workspace_id', $this->workspace->id())->where('id', $item['id'])->where('kind', $item['type'])->whereNull('archived_at')->lockForUpdate()->first();
                abort_unless($record, 404);
                abort_unless((int) $record->revision === (int) $item['expected_revision'], 409, 'Документ изменился. Обновите список.');
                $relation = DB::table('practice_program_folder_'.$kind.'s');
                if ($input['remove'] ?? false) {
                    $relation->where('folder_id', $id)->where($kind.'_id', $record->id)->delete();
                } else {
                    $relation->insertOrIgnore(['folder_id' => $id, $kind.'_id' => $record->id]);
                }
            }
            $this->folders()->where('id', $id)->update(['revision' => $folder->revision + 1, 'updated_at' => now()]);
        });

        return response()->json(['data' => $this->folders()->where('id', $id)->first()]);
    }

    public function library(Request $request): JsonResponse
    {
        $input = $request->validate(['folder_id' => 'nullable|uuid', 'type' => 'sometimes|in:all,practice,cycle', 'q' => 'nullable|string|max:200', 'archive' => 'sometimes|in:active,archived', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        if (! empty($input['folder_id'])) {
            abort_unless($this->folders()->where('id', $input['folder_id'])->whereNull('archived_at')->exists(), 404);
        }
        $queryFor = function (string $kind) use ($input) {
            $table = $kind === 'cycle' ? 'practice_mesocycles' : 'practice_documents';
            $query = DB::table($table)->where('workspace_id', $this->workspace->id())->where('kind', $kind);
            ($input['archive'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
            if ($search = trim($input['q'] ?? '')) {
                $query->whereRaw("replace(lower(title), 'ё', 'е') like ?", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(str_replace('ё', 'е', $search))).'%']);
            }
            if (! empty($input['folder_id'])) {
                $type = $kind === 'cycle' ? 'cycle' : 'document';
                $query->whereIn('id', DB::table('practice_program_folder_'.$type.'s')->where('folder_id', $input['folder_id'])->select($type.'_id'));
            }

            return $query->select('id', 'title', 'theme', 'revision', 'archived_at', 'updated_at')->selectRaw("'".$kind."' as type");
        };
        $query = match ($input['type'] ?? 'all') {
            'practice' => $queryFor('practice'), 'cycle' => $queryFor('cycle'),
            default => DB::query()->fromSub($queryFor('cycle')->unionAll($queryFor('practice')), 'programs'),
        };
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($input['per_page'] ?? 24);

        return response()->json(['data' => $page->items(), 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }
}
