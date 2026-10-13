<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Practices\Actions\MesocycleEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MesocycleController
{
    public function __construct(private readonly MesocycleEditor $editor) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->editor->listing($request->validate(['q' => 'nullable|string|max:255', 'archive' => 'sometimes|in:active,archived', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1'])));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->show($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->editor->create($request->validate(['title' => 'required|string|max:160', 'theme' => 'nullable|string|max:240', 'base_document_id' => 'nullable|uuid']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->update($id, $request->validate(['expected_revision' => 'required|integer|min:1', 'title' => 'required|string|max:160', 'theme' => 'nullable|string|max:240', 'cloud_asana_ids' => 'required|array|list|max:500', 'cloud_asana_ids.*' => 'required|uuid|distinct:ignore_case']))]);
    }

    public function replaceWeek(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->replaceWeek($id, $request->validate(['expected_revision' => 'required|integer|min:1', 'position' => 'required|integer|min:0|max:4', 'source_document_id' => 'required|uuid']))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, (int) $request->validate(['expected_revision' => 'required|integer|min:1'])['expected_revision'], false)]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, (int) $request->validate(['expected_revision' => 'required|integer|min:1'])['expected_revision'], true)]);
    }
}
