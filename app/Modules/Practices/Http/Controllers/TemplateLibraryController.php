<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A single index over the existing immutable-copy template models. */
class TemplateLibraryController
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['type' => 'sometimes|in:practice,fragment,cycle', 'q' => 'nullable|string|max:200', 'archive' => 'sometimes|in:active,archived', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $type = $filters['type'] ?? 'practice';
        $scoped = function (string $table) use ($filters) {
            $query = DB::table($table)->where('workspace_id', $this->workspace->id());
            ($filters['archive'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
            if ($search = trim($filters['q'] ?? '')) {
                $query->whereRaw("replace(lower(title), 'ё', 'е') like ?", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(str_replace('ё', 'е', $search))).'%']);
            }

            return $query;
        };
        if ($type === 'cycle') {
            $query = $scoped('practice_mesocycles')->where('kind', 'template')->select('id', 'title', 'revision', 'updated_at', 'archived_at')->selectRaw("'cycle' as source_type, 'cycle' as subtype");
        } elseif ($type === 'practice') {
            $query = $scoped('practice_documents')->where('kind', 'template')->select('id', 'title', 'revision', 'updated_at', 'archived_at')->selectRaw("'document' as source_type, 'practice' as subtype");
        } else {
            $rows = $scoped('practice_row_templates')->select('id', 'title', 'revision', 'updated_at', 'archived_at')->selectRaw("'row' as source_type, kind as subtype");
            $blocks = $scoped('practice_documents')->where('kind', 'fragment')->select('id', 'title', 'revision', 'updated_at', 'archived_at')->selectRaw("'document' as source_type, coalesce(fragment_kind, 'sequence') as subtype");
            $query = DB::query()->fromSub($rows->unionAll($blocks), 'fragments');
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($filters['per_page'] ?? 24);

        return response()->json(['data' => $page->items(), 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }
}
