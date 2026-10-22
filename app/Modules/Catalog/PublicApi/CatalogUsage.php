<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use Illuminate\Support\Facades\DB;

class CatalogUsage
{
    public function __construct(private WorkspaceContext $workspace) {}

    /** Call only after successful insertion, inside the caller's transaction. */
    public function record(array $asanaIds): void
    {
        $ids = Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->whereIn('id', $asanaIds)->pluck('id');
        foreach ($ids as $id) {
            DB::table('catalog_asana_preferences')->upsert([
                ['workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'asana_id' => $id, 'favorite' => false, 'last_used_at' => now()],
            ], ['workspace_id', 'user_id', 'asana_id'], ['last_used_at']);
        }
    }
}
