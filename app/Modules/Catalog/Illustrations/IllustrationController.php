<?php

namespace App\Modules\Catalog\Illustrations;

use App\Modules\Catalog\PublicApi\IllustrationStyles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IllustrationController
{
    public function __construct(private IllustrationStyles $styles) {}

    public function state(): JsonResponse
    {
        return response()->json(['data' => $this->styles->state()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'style' => ['required', 'string', 'max:24'], 'character' => ['required', 'string', 'max:24']]);

        return response()->json(['data' => $this->styles->savePreference($data['style'], $data['character'], $data['expected_revision'])]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate(['asana_id' => ['required', 'uuid'], 'source_asset_id' => ['required', 'uuid'], 'style' => ['required', 'string', 'max:24'], 'character' => ['required', 'string', 'max:24']]);

        return response()->json(['data' => $this->styles->prepare($data['asana_id'], $data['source_asset_id'], $data['style'], $data['character'])]);
    }
}
