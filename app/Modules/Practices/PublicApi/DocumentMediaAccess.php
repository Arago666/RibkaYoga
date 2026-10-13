<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Practices\Services\DocumentSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentMediaAccess
{
    public function __construct(private DocumentSharing $sharing) {}

    public function authorize(string $documentId, string $assetId): void
    {
        abort_unless(Str::isUuid($documentId) && Str::isUuid($assetId), 404);
        $access = $this->sharing->access($documentId);
        $exists = DB::table('practice_cells as cells')->join('practice_rows as rows', 'rows.id', '=', 'cells.row_id')
            ->join('practice_blocks as blocks', 'blocks.id', '=', 'rows.block_id')
            ->join('practice_card_snapshots as snapshots', 'snapshots.id', '=', 'cells.card_snapshot_id')
            ->where('blocks.document_id', $access['document']->id)->where('snapshots.workspace_id', $access['document']->workspace_id)
            ->where('snapshots.image_asset_id', strtolower($assetId))->exists();
        abort_unless($exists, 404);
    }
}
