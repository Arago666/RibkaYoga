<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\DrawingSources;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Immutable drawing source associated with the concrete image used in a document. */
class NativeImages
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private DrawingSources $validator) {}

    public function remember(string $assetId, mixed $source, bool $allowEmpty = false): void
    {
        if ($source === null) {
            return;
        }
        $drawing = $this->validator->validate($source, $allowEmpty);
        $asset = $this->assets->originalData($assetId);
        $bytes = base64_decode(explode(',', $asset['data_url'], 2)[1] ?? '', true);
        abort_unless(is_string($bytes) && $bytes !== '', 422, 'Не удалось закрепить исходник изображения.');
        $existing = DB::table('practice_native_sources')->where('workspace_id', $this->workspace->id())->where('image_asset_id', $assetId)->first();
        if ($existing) {
            abort_unless(json_decode($existing->drawing_source, true) === $drawing && hash_equals($existing->image_sha256, hash('sha256', $bytes)), 409, 'Для этого изображения уже закреплён другой исходник.');

            return;
        }
        DB::table('practice_native_sources')->insertOrIgnore(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id(), 'image_asset_id' => $assetId, 'drawing_source' => json_encode($drawing, JSON_THROW_ON_ERROR), 'image_sha256' => hash('sha256', $bytes), 'created_by' => $this->workspace->userId(), 'created_at' => now()]);
        $pinned = DB::table('practice_native_sources')->where('workspace_id', $this->workspace->id())->where('image_asset_id', $assetId)->first();
        abort_unless($pinned && json_decode($pinned->drawing_source, true) === $drawing && hash_equals($pinned->image_sha256, hash('sha256', $bytes)), 409, 'Для этого изображения уже закреплён другой исходник.');
    }

    public function source(string $assetId): ?array
    {
        $this->assets->get($assetId);
        $row = DB::table('practice_native_sources')->where('workspace_id', $this->workspace->id())->where('image_asset_id', $assetId)->first();

        return $row ? json_decode($row->drawing_source, true, 64, JSON_THROW_ON_ERROR) : null;
    }
}
