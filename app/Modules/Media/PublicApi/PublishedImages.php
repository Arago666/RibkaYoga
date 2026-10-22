<?php

namespace App\Modules\Media\PublicApi;

use App\Modules\Media\Models\Asset;
use App\Modules\Practices\PublicApi\PublicationMediaAccess;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** A publication grants access only to its explicit image allowlist. */
class PublishedImages
{
    public function file(string $token, string $assetId): array
    {
        abort_unless(Str::isUuid($assetId), 404);
        $workspaceId = app(PublicationMediaAccess::class)->authorize($token, $assetId);
        $asset = Asset::where('workspace_id', $workspaceId)->findOrFail($assetId);
        abort_unless(in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->original_path), 404);

        return ['path' => $disk->path($asset->original_path), 'mime' => $asset->mime];
    }
}
