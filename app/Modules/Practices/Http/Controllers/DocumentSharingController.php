<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\Services\DocumentSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentSharingController
{
    public function __construct(private DocumentSharing $sharing) {}

    public function permissions(string $id): JsonResponse
    {
        return response()->json($this->sharing->permissions($id));
    }

    public function grant(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'login' => ['required', 'string', 'max:64'], 'permission' => ['required', Rule::in(['view', 'comment'])],
            'expected_document_revision' => ['required', 'integer', 'min:1'], 'expected_revision' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($this->sharing->grant($id, $input));
    }

    public function revoke(Request $request, string $id, string $shareId): JsonResponse
    {
        $input = $request->validate(['expected_document_revision' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:1']]);

        return response()->json($this->sharing->revoke($id, $shareId, $input));
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->sharing->listing($input));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->sharing->viewer($id)]);
    }

    public function media(string $id, string $assetId, AssetStore $assets): BinaryFileResponse
    {
        $file = $assets->forDocument($assetId, $id);

        return response()->file($file['path'], [
            'Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'",
        ])->setPrivate();
    }

    public function addComment(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'], 'cell_id' => ['nullable', 'uuid'], 'share_id' => ['nullable', 'uuid'],
            'expected_document_revision' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->sharing->addComment($id, $input)], 201);
    }

    public function editComment(Request $request, string $id, string $commentId): JsonResponse
    {
        $input = $request->validate(['body' => ['required', 'string', 'max:2000'], 'expected_revision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->sharing->changeComment($id, $commentId, $input)]);
    }

    public function deleteComment(Request $request, string $id, string $commentId): JsonResponse
    {
        $input = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->sharing->changeComment($id, $commentId, $input, true)]);
    }
}
