<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\Services\DocumentSharing;
use App\Modules\Practices\Services\SheetRenderer;
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
            'allow_copy' => ['sometimes', 'boolean'],
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

    public function sheet(Request $request, string $id, SheetRenderer $renderer)
    {
        $input = $request->validate(['show_names' => 'sometimes|boolean', 'orientation' => 'sometimes|in:portrait,landscape']);
        $document = $this->sharing->viewer($id)['document'];
        $images = [];
        foreach ($document['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['card']) {
                        $images[$cell['card_snapshot_id']] = $cell['card']['image_url'];
                    }
                }
            }
        }
        $names = (bool) ($input['show_names'] ?? false);

        return response($renderer->render($document, $images, ['show_names' => $names, 'preset' => $names ? 'names' : 'clean', 'orientation' => $input['orientation'] ?? 'landscape']))->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
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
