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
        return response()->json($this->editor->listing($request->validate(['kind' => 'sometimes|in:cycle,template', 'q' => 'nullable|string|max:255', 'archive' => 'sometimes|in:active,archived', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1'])));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->show($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->editor->create($request->validate(['title' => 'required|string|max:160', 'theme' => 'nullable|string|max:240', 'base_document_id' => 'nullable|uuid', 'expected_source_revision' => 'sometimes|integer|min:1', 'count' => 'sometimes|integer|min:1|max:52', 'kind' => 'sometimes|in:cycle,template', 'idempotency_key' => 'sometimes|uuid']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->update($id, $request->validate(['expected_revision' => 'required|integer|min:1', 'title' => 'sometimes|required|string|max:160', 'theme' => 'sometimes|nullable|string|max:240', 'cloud_asana_ids' => 'sometimes|array|list|max:500', 'cloud_asana_ids.*' => 'required|uuid|distinct:ignore_case']))]);
    }

    public function replaceWeek(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->replaceWeek($id, $request->validate(['expected_revision' => 'required|integer|min:1', 'position' => 'required|integer|min:0|max:51', 'source_document_id' => 'required|uuid', 'expected_source_revision' => 'sometimes|integer|min:1', 'idempotency_key' => 'sometimes|uuid']))]);
    }

    public function structure(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'idempotency_key' => 'required|uuid', 'positions' => 'required|array|list|min:1|max:52', 'positions.*.id' => 'nullable|uuid|distinct', 'positions.*.title' => 'nullable|string|max:160', 'removed' => 'sometimes|array|list|max:52', 'removed.*.id' => 'required|uuid|distinct', 'removed.*.action' => 'required|in:keep,archive', 'removed.*.expected_revision' => 'required|integer|min:1']);

        return response()->json(['data' => $this->editor->structure($id, $input)]);
    }

    public function insert(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'idempotency_key' => 'required|uuid', 'document_id' => 'required|uuid', 'expected_document_revision' => 'required|integer|min:1', 'cell_id' => 'required|uuid', 'asana_id' => 'required|uuid', 'confirm_replace' => 'sometimes|boolean']);

        return response()->json(['data' => $this->editor->insert($id, $input)]);
    }

    public function undoInsert(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->undoInsert($id, $request->validate(['operation_id' => 'required|uuid', 'idempotency_key' => 'required|uuid']))]);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'title' => 'required|string|max:160', 'kind' => 'required|in:cycle,template', 'idempotency_key' => 'required|uuid']);

        return response()->json(['data' => $this->editor->duplicate($id, $input)], 201);
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
