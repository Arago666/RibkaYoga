<?php

namespace App\Modules\Sketchbook\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/** Personal sketchbook files do not become visible to other workspace members. */
class SketchImages
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function authorize(array $assetIds): void
    {
        $owners = DB::table('sketchbook_image_owners')->where('workspace_id', $this->workspace->id())
            ->whereIn('image_asset_id', $assetIds)->get(['image_asset_id', 'user_id'])->groupBy('image_asset_id');
        foreach ($owners as $pages) {
            abort_unless($pages->contains(fn ($page) => (int) $page->user_id === $this->workspace->userId()), 404);
        }
    }

    public function remember(string $assetId): void
    {
        DB::table('sketchbook_image_owners')->insertOrIgnore(['image_asset_id' => $assetId, 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'created_at' => now()]);
        $this->authorize([$assetId]);
    }
}
