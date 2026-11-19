<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_asanas', fn (Blueprint $table) => $table->boolean('starter_card')->default(false));

        // Original seed copies have the packaged image metadata and were created with their workspace.
        // Collection membership is editable and cannot be used as provenance.
        $root = resource_path('imports/spinal-five-20261102/starter');
        $tables = json_decode(gzdecode(file_get_contents($root.'/dataset.json.gz')), true, flags: JSON_THROW_ON_ERROR)['tables'];
        $media = array_column($tables['media_assets'], null, 'id');
        $manifest = [];
        foreach ($tables['catalog_asanas'] as $source) {
            if ($source['archived_at'] === null) {
                $asset = $media[$source['current_image_asset_id']];
                $key = $asset['original_filename'].'|'.$asset['bytes'];
                $manifest[$key] = ($manifest[$key] ?? 0) + 1;
            }
        }
        DB::table('workspaces')->select(['id', 'created_by', 'created_at'])->chunkById(100, function ($workspaces) use ($manifest) {
            foreach ($workspaces as $workspace) {
                $remaining = $manifest;
                $started = Carbon::parse($workspace->created_at);
                $ids = [];
                $candidates = DB::table('catalog_asanas as asana')->join('media_assets as asset', 'asset.id', '=', 'asana.current_image_asset_id')
                    ->where('asana.workspace_id', $workspace->id)->where('asana.created_by', $workspace->created_by)
                    ->whereBetween('asana.created_at', [$started, $started->copy()->addMinutes(10)])
                    ->whereColumn('asset.workspace_id', 'asana.workspace_id')
                    ->orderBy('asana.created_at')->orderBy('asana.id')
                    ->get(['asana.id', 'asset.original_filename', 'asset.bytes']);
                foreach ($candidates as $card) {
                    $key = $card->original_filename.'|'.$card->bytes;
                    if (($remaining[$key] ?? 0) > 0) {
                        $ids[] = $card->id;
                        $remaining[$key]--;
                    }
                }
                if ($ids) {
                    DB::table('catalog_asanas')->whereIn('id', $ids)->update(['starter_card' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_asanas', fn (Blueprint $table) => $table->dropColumn('starter_card'));
    }
};
