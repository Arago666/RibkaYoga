<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;

class PoseFacts
{
    public function __construct(private WorkspaceContext $workspace) {}

    /** Facts apply only to the exact current image, never guessed for historical snapshots. */
    public function forImages(array $pairs): array
    {
        $cards = Asana::where('workspace_id', $this->workspace->id())->whereIn('id', array_column($pairs, 'asana_id'))->with('categories')->get()->keyBy('id');
        $result = [];
        foreach ($pairs as $pair) {
            $card = $cards->get($pair['asana_id'] ?? '');
            if (! $card || $card->current_image_asset_id !== ($pair['image_asset_id'] ?? null)) {
                continue;
            }
            $result[$card->current_image_asset_id] = [
                'revision' => $card->revision,
                'anatomy' => $card->categories->where('dimension', 'anatomy')->map(fn ($category) => ['key' => $category->system_key, 'name' => $category->name])->values()->all(),
                'difficulty' => $card->categories->firstWhere('dimension', 'difficulty')?->name,
            ];
        }

        return $result;
    }
}
