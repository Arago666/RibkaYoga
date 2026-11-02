<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Practices\Services\PracticeReviews;
use Illuminate\Http\Request;

class PracticeReviewController
{
    public function __construct(private PracticeReviews $reviews) {}

    private function listingInput(Request $request): array
    {
        return $request->validate(['q' => 'nullable|string|max:160', 'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100', 'scope' => 'sometimes|in:all,mine,shared',
            'archive' => 'sometimes|in:active,archived']);
    }

    public function index(Request $request)
    {
        return response()->json($this->reviews->listing($this->listingInput($request)))->header('Cache-Control', 'private, no-store');
    }

    public function sources(Request $request)
    {
        return response()->json($this->reviews->sources($this->listingInput($request)))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $input = $request->validate(['id' => 'required|uuid', 'document_id' => 'required|uuid',
            'expected_document_revision' => 'required|integer|min:1', 'title' => 'sometimes|nullable|string|max:160']);

        return response()->json(['data' => $this->reviews->create($input)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(string $id)
    {
        return response()->json(['data' => $this->reviews->read($id)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'title' => 'required|string|max:160',
            'annotations' => 'required|array']);

        return response()->json(['data' => $this->reviews->save($id, $input)])->header('Cache-Control', 'private, no-store');
    }

    public function copy(Request $request, string $id)
    {
        $input = $request->validate(['id' => 'required|uuid', 'expected_revision' => 'required|integer|min:1',
            'title' => 'sometimes|nullable|string|max:160', 'annotations' => 'sometimes|array']);

        return response()->json(['data' => $this->reviews->copy($id, $input)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function archive(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1']);

        return response()->json(['data' => $this->reviews->archive($id, (int) $input['expected_revision'])])->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1']);

        return response()->json(['data' => $this->reviews->archive($id, (int) $input['expected_revision'], true)])->header('Cache-Control', 'private, no-store');
    }

    public function media(string $id, string $assetId)
    {
        $file = $this->reviews->media($id, $assetId);

        return response()->file($file['path'], ['Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'"])->setPrivate();
    }
}
