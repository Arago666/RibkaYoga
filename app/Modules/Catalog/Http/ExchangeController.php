<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Services\CatalogExchange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeController
{
    public function export(Request $request, CatalogExchange $exchange): JsonResponse
    {
        $data = $request->validate(['asana_ids' => ['sometimes', 'array', 'list', 'max:100'], 'asana_ids.*' => ['uuid', 'distinct']]);

        return response()->json($exchange->export($data['asana_ids'] ?? []));
    }

    public function import(Request $request, CatalogExchange $exchange): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 48 * 1024 * 1024, 413, 'JSON превышает 48 МБ.');

        return response()->json($exchange->import($request->all()));
    }
}
