<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Practices\Services\PracticeIllustrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeIllustrationController
{
    public function __construct(private PracticeIllustrations $illustrations) {}

    public function preview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'style' => ['required', 'in:original,line,volume'], 'character' => ['required', 'in:neutral,female,male']]);

        return response()->json(['data' => $this->illustrations->preview($id, (int) $data['expected_revision'], $data['style'], $data['character'])]);
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'token' => ['required', 'string', 'size:40', 'alpha_num:ascii'], 'snapshot_ids' => ['required', 'array', 'list', 'min:1', 'max:1000'], 'snapshot_ids.*' => ['required', 'uuid', 'distinct:ignore_case']]);

        return response()->json(['data' => $this->illustrations->apply($id, (int) $data['expected_revision'], $data['token'], array_map('strtolower', $data['snapshot_ids']))]);
    }
}
