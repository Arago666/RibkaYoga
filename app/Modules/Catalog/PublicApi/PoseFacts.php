<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use Illuminate\Support\Facades\DB;

class PoseFacts
{
    public function __construct(private WorkspaceContext $workspace) {}

    /** Facts apply to the current image or its verified variant, never an older source image. */
    public function forImages(array $pairs): array
    {
        return DB::transaction(function () use ($pairs) {
            $cards = Asana::where('workspace_id', $this->workspace->id())->whereIn('id', array_column($pairs, 'asana_id'))->orderBy('id')->sharedLock()->with('categories')->get()->keyBy('id');
            $variants = DB::table('catalog_illustration_variants')->where('workspace_id', $this->workspace->id())
                ->whereIn('asana_id', $cards->keys())->whereIn('image_asset_id', array_column($pairs, 'image_asset_id'))
                ->get(['asana_id', 'source_asset_id', 'image_asset_id'])->groupBy('asana_id');
            $result = [];
            foreach ($pairs as $pair) {
                $card = $cards->get($pair['asana_id'] ?? '');
                $assetId = $pair['image_asset_id'] ?? null;
                if (! $card || ! is_string($assetId)) {
                    continue;
                }
                $currentVariant = ($variants[$card->id] ?? collect())->contains(fn ($variant) => $variant->image_asset_id === $assetId && $variant->source_asset_id === $card->current_image_asset_id);
                if ($card->current_image_asset_id !== $assetId && ! $currentVariant) {
                    continue;
                }
                $result[$assetId] = [
                    'revision' => $card->revision,
                    'anatomy' => $card->categories->where('dimension', 'anatomy')->map(fn ($category) => ['key' => $category->system_key, 'name' => $category->name])->values()->all(),
                    'difficulty' => $card->categories->firstWhere('dimension', 'difficulty')?->name,
                ];
            }

            return $result;
        });
    }
}
