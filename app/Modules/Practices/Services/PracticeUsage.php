<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Practices\PublicApi\RecentAsanas;
use Illuminate\Support\Facades\DB;

class PracticeUsage implements RecentAsanas
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function ids(): array
    {
        return DB::table('practice_cells as cells')
            ->join('practice_rows as rows', 'rows.id', '=', 'cells.row_id')
            ->join('practice_blocks as blocks', 'blocks.id', '=', 'rows.block_id')
            ->join('practice_documents as documents', 'documents.id', '=', 'blocks.document_id')
            ->join('practice_card_snapshots as snapshots', 'snapshots.id', '=', 'cells.card_snapshot_id')
            ->where('documents.workspace_id', $this->context->id())
            ->where('snapshots.workspace_id', $this->context->id())
            ->where('documents.kind', 'practice')->whereNull('documents.archived_at')
            ->whereNotNull('snapshots.source_asana_id')
            ->groupBy('snapshots.source_asana_id')
            ->select('snapshots.source_asana_id')->selectRaw('max(documents.updated_at) as last_used_at')
            ->orderByDesc('last_used_at')->orderBy('snapshots.source_asana_id')
            ->limit(500)->pluck('snapshots.source_asana_id')->all();
    }
}
