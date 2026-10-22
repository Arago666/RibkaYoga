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
            'illustration_style' => ['sometimes', 'nullable', 'in:original,line,volume'],
            'illustration_character' => ['sometimes', 'nullable', 'in:neutral,female,male'],
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
        $data = $request->validate([
            'asana_ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'asana_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'illustration_variants' => ['sometimes', 'array', 'list', 'max:100'],
            'illustration_variants.*.asana_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'illustration_variants.*.variant_id' => ['present', 'nullable', 'uuid'],
            'illustration' => ['sometimes', 'array:style,character'],
            'illustration.style' => ['required_with:illustration', 'in:original,line,volume'],
            'illustration.character' => ['required_with:illustration', 'in:neutral,female,male'],
        ]);
        $ids = array_map('strtolower', $data['asana_ids']);
        $variants = [];
        foreach ($data['illustration_variants'] ?? [] as $variant) {
            $asanaId = strtolower($variant['asana_id']);
            abort_unless(in_array($asanaId, $ids, true), 422, 'Вариант должен относиться к выбранной асане.');
            $variants[$asanaId] = $variant['variant_id'] ? strtolower($variant['variant_id']) : null;
        }

        return response()->json(['data' => $this->editor->resolveCards($ids, $variants, $data['illustration'] ?? ['style' => 'original', 'character' => 'neutral'])], 201);
    }

    public function refreshPreview(string $id): JsonResponse
    {
        return response()->json(['data' => $this->editor->refreshPreview($id)]);
    }

    public function refreshSnapshots(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => 'required|integer|min:1', 'source_asana_ids' => 'required|array|list|min:1|max:1000', 'source_asana_ids.*' => 'required|uuid|distinct:ignore_case', 'expected_asana_revisions' => 'required|array|list|min:1|max:1000', 'expected_asana_revisions.*.asana_id' => 'required|uuid|distinct:ignore_case', 'expected_asana_revisions.*.revision' => 'required|integer|min:1']);
        $expected = array_map(fn ($entry) => ['asana_id' => strtolower($entry['asana_id']), 'revision' => (int) $entry['revision']], $data['expected_asana_revisions']);

        return response()->json(['data' => $this->editor->refreshSnapshots($id, (int) $data['expected_revision'], array_map('strtolower', $data['source_asana_ids']), $expected)]);
    }

    public function fragmentStore(DocumentRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->editor->saveFragment($request->validated())], 201);
    }

    public function resolveSnapshots(Request $request): JsonResponse
    {
        $input = $request->validate(['snapshot_ids' => 'required|array|list|min:1|max:100', 'snapshot_ids.*' => 'required|uuid|distinct:ignore_case']);

        return response()->json(['data' => $this->editor->readSnapshots($input['snapshot_ids'])]);
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
        if ($request->has('include_cycle_documents')) {
            $request->merge(['include_cycle_documents' => $request->boolean('include_cycle_documents')]);
        }

        return $request->validate([
            'kind' => ['sometimes', Rule::in(['practice', 'template', 'fragment', 'row', 'transition'])],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['updated_desc', 'updated_asc', 'title_asc', 'title_desc', 'created_desc', 'created_asc'])],
            'archive' => ['sometimes', Rule::in(['active', 'archived'])],
            'archived' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'include_cycle_documents' => ['sometimes', 'boolean'],
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
