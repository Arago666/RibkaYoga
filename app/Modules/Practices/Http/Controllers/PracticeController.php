<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\Http\Requests\DocumentRequest;
use App\Modules\Practices\Http\Requests\RowTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PracticeController
{
    public function __construct(private readonly PracticeEditor $editor) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->editor->listing($this->filters($request)));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->document($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(['practice', 'template'])],
            'theme' => ['nullable', 'string', 'max:240'],
            'note' => ['nullable', 'string', 'max:3000'],
            'practice_date' => ['nullable', 'date_format:Y-m-d'],
            'template_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->editor->create($data)], 201);
    }

    public function update(DocumentRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->save($id, $request->validated())]);
    }

    public function copyLocal(DocumentRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->editor->copyLocal($request->validated())], 201);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->duplicate($id, $this->copyFields($request))], 201);
    }

    public function saveAsTemplate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->duplicate($id, $this->copyFields($request), true)], 201);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, $this->revision($request))]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, $this->revision($request), true)]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['asana_ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'asana_ids.*' => ['required', 'uuid', 'distinct:ignore_case']]);

        return response()->json(['data' => $this->editor->resolveCards(array_map('strtolower', $data['asana_ids']))], 201);
    }

    public function rowIndex(Request $request): JsonResponse
    {
        return response()->json($this->editor->listing($this->filters($request), true));
    }

    public function rowShow(string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->rowTemplate($id)]);
    }

    public function rowStore(RowTemplateRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->editor->saveRowTemplate(null, $request->validated())], 201);
    }

    public function rowUpdate(RowTemplateRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->saveRowTemplate($id, $request->validated())]);
    }

    public function rowArchive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, $this->revision($request), false, true)]);
    }

    public function rowRestore(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->archive($id, $this->revision($request), true, true)]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'kind' => ['sometimes', Rule::in(['practice', 'template'])],
            'q' => ['nullable', 'string', 'max:255'],
            'archive' => ['sometimes', Rule::in(['active', 'archived'])],
            'archived' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function copyFields(Request $request): array
    {
        return $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'expected_revision' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['expected_revision' => ['required', 'integer', 'min:1']])['expected_revision'];
    }
}
