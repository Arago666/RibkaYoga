<?php

namespace App\Modules\Media\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\Models\Asset;
use App\Modules\Sketchbook\PublicApi\SketchImages;
use Illuminate\Support\Facades\Storage;

/** Streams immutable local image files through SHA-256 without exporting their bytes. */
class AssetFingerprints
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function forMany(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        app(SketchImages::class)->authorize($ids);
        $assets = Asset::where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->get();
        abort_unless($assets->count() === count($ids), 404);
        $result = [];
        foreach ($assets as $asset) {
            $path = Storage::disk($asset->disk)->path($asset->original_path);
            abort_unless(is_file($path), 404);
            $result[$asset->id] = hash_file('sha256', $path);
        }

        return $result;
    }
}
