<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\DrawingSources;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DrawingSnapshots
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private NativeImages $native, private DrawingSources $sources) {}

    public function create(string $id, string $name, UploadedFile $file, mixed $drawing): array
    {
        $source = $this->sources->validate($drawing);
        abort_unless($source, 422);

        $asset = null;
        try {
            return DB::transaction(function () use ($id, $name, $file, $source, &$asset) {
                $old = DB::table('practice_card_snapshots')->where('id', $id)->lockForUpdate()->first();
                if ($old) {
                    abort_unless($old->workspace_id === $this->workspace->id() && $old->source_asana_id === null, 409);

                    abort_unless($old->name === $name && $this->native->source($old->image_asset_id) === $source, 409, 'Подготовленная фигура изменилась. Выделите её заново.');

                    return $this->snapshot($old);
                }
                $asset = $this->assets->store($file);
                abort_unless($asset['mime'] === 'image/png' && (int) $asset['width'] === 1024 && (int) $asset['height'] === 1024, 422, 'Нужен PNG 1024 × 1024.');
                $this->native->remember($asset['id'], $source);
                DB::table('practice_card_snapshots')->insert(['id' => $id, 'workspace_id' => $this->workspace->id(), 'source_asana_id' => null, 'name' => $name, 'image_asset_id' => $asset['id'], 'created_at' => now()]);

                return $this->snapshot(DB::table('practice_card_snapshots')->where('id', $id)->first());
            });
        } catch (\Throwable $failure) {
            if ($asset) {
                $this->assets->discardUnattached($asset['id']);
            }
            throw $failure;
        }
    }

    private function snapshot(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'source_asana_id' => null, 'image_url' => $this->assets->get($row->image_asset_id)['image_url']];
    }
}
