<?php

namespace App\Modules\Media\Http;

use App\Modules\Media\PublicApi\AudioStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AudioController
{
    public function __construct(private AudioStore $audio) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->audio->list($request->boolean('archived')), 'limits' => $this->audio->limits()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['audio' => ['required', 'file', 'max:'.(int) ceil($this->audio->limits()['max_bytes'] / 1024)], 'title' => ['required', 'string', 'max:160', 'regex:/\S/u']]);

        return response()->json(['data' => $this->audio->store($data['audio'], $data['title'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'title' => ['sometimes', 'string', 'max:160', 'regex:/\S/u'], 'archived' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->audio->update($id, $data['expected_revision'], $data['title'] ?? null, $data['archived'] ?? null)]);
    }

    public function stream(string $id): BinaryFileResponse
    {
        $asset = $this->audio->stream($id);

        // Symfony prepares byte ranges without loading the file into PHP memory.
        return response()->file($asset['path'], ['Content-Type' => $asset['mime'], 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"])->setPrivate();
    }
}
