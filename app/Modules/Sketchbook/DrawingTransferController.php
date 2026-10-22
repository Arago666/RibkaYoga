<?php

namespace App\Modules\Sketchbook;

use App\Modules\Practices\PublicApi\DrawingSnapshots;
use App\Modules\Practices\PublicApi\NativeImages;
use Illuminate\Http\Request;

class DrawingTransferController
{
    public function store(Request $request, DrawingSnapshots $snapshots)
    {
        $input = $request->validate(['id' => 'required|uuid', 'name' => 'required|string|max:160', 'image' => 'required|file', 'drawing_source' => 'required']);

        return response()->json(['data' => $snapshots->create($input['id'], trim($input['name']), $request->file('image'), $input['drawing_source'])], 201);
    }

    public function source(string $id, NativeImages $native)
    {
        return response()->json(['data' => $native->source($id)]);
    }
}
