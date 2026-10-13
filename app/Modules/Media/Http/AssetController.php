<?php

namespace App\Modules\Media\Http;

use App\Modules\Media\Services\PrivateAssetStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController
{
    public function __invoke(Request $request, string $id, PrivateAssetStore $store): BinaryFileResponse
    {
        $asset = $store->find($id);
        $thumbnail = $request->input('variant') === 'thumbnail';
        $path = Storage::disk($asset->disk)->path($thumbnail ? $asset->thumbnail_path : $asset->original_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $thumbnail && $asset->thumbnail_path !== $asset->original_path ? 'image/webp' : $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'",
        ])->setPrivate();
    }
}
