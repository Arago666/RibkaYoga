<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Media\PublicApi\PublishedImages;
use App\Modules\Practices\Services\DocumentSharing;
use App\Modules\Practices\Services\PortablePractices;
use App\Modules\Practices\Services\PublicPublications;
use Illuminate\Http\Request;

class PortablePracticeController
{
    public function __construct(private PortablePractices $portable) {}

    public function export(string $id)
    {
        return response()->json($this->portable->export($id))->header('Content-Disposition', 'attachment; filename="ribka-practice-'.$id.'.json"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function preview(Request $request)
    {
        $input = $request->validate(['bundle' => 'required|array']);

        return response()->json(['data' => $this->portable->preview($input['bundle'])]);
    }

    public function import(Request $request)
    {
        $input = $request->validate(['bundle' => 'required|array', 'idempotency_key' => 'required|uuid', 'title' => 'nullable|string|max:160']);

        return response()->json(['data' => $this->portable->import($input['bundle'], $input['idempotency_key'], $input['title'] ?? null)], 201);
    }

    public function shared(Request $request, string $id, DocumentSharing $sharing)
    {
        $input = $request->validate(['idempotency_key' => 'required|uuid']);

        return response()->json(['data' => $this->portable->copyShared($id, $input['idempotency_key'], $sharing)], 201);
    }

    public function published(Request $request, string $token, PublicPublications $publications, PublishedImages $images)
    {
        $input = $request->validate(['idempotency_key' => 'required|uuid']);

        return response()->json(['data' => $this->portable->copyPublished($token, $input['idempotency_key'], $publications, $images)], 201);
    }
}
