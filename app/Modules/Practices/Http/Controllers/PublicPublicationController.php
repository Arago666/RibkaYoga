<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Media\PublicApi\PublishedImages;
use App\Modules\Practices\Services\PublicPublications;
use App\Modules\Practices\Services\SheetRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicPublicationController
{
    public function __construct(private PublicPublications $publications) {}

    public function page(string $token)
    {
        $this->publications->resolve($token);

        return Inertia::render('practices/Published', ['token' => $token])->toResponse(request())->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function run(string $token)
    {
        $this->publications->resolve($token);

        return Inertia::render('sessions/Player', ['publicToken' => $token])->toResponse(request())->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(string $token)
    {
        return response()->json(['data' => $this->publications->viewer($token)])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function plan(string $token)
    {
        return response()->json(['data' => $this->publications->viewer($token)['plan']])->header('Cache-Control', 'private, no-store');
    }

    public function sheet(string $token, SheetRenderer $renderer)
    {
        $data = $this->publications->viewer($token);
        $images = [];
        foreach ($data['document']['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['card']) {
                        $images[$cell['card_snapshot_id']] = $cell['card']['image_url'];
                    }
                }
            }
        }

        return response($renderer->render($data['document'], $images, array_replace($data['options'], ['preset' => $data['options']['show_names'] ? 'names' : 'clean'])))->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
    }

    public function media(string $token, string $assetId, PublishedImages $images)
    {
        $file = $images->file($token, $assetId);

        return response()->file($file['path'], ['Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'", 'Referrer-Policy' => 'no-referrer'])->setPrivate();
    }

    public function qr(string $token)
    {
        $this->publications->resolve($token);
        $renderer = new ImageRenderer(new RendererStyle(256), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString(url('/p/'.$token));

        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Content-Security-Policy', "default-src 'none'");
    }

    public function index(string $id)
    {
        return response()->json(['data' => $this->publications->listing($id)]);
    }

    public function store(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'show_names' => 'sometimes|boolean', 'include_notes' => 'sometimes|boolean', 'allow_copy' => 'sometimes|boolean', 'expires_at' => 'nullable|date', 'orientation' => 'sometimes|in:portrait,landscape']);

        return response()->json(['data' => $this->publications->create($id, $input)], 201);
    }

    public function preview(Request $request, string $id, SheetRenderer $renderer)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'show_names' => 'sometimes|boolean', 'include_notes' => 'sometimes|boolean', 'allow_copy' => 'sometimes|boolean', 'expires_at' => 'nullable|date', 'orientation' => 'sometimes|in:portrait,landscape']);
        $data = $this->publications->preview($id, $input);
        $images = [];
        foreach ($data['document']['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['card']) {
                        $images[$cell['card_snapshot_id']] = $cell['card']['image_url'];
                    }
                }
            }
        }

        return response()->json(['data' => ['html' => $renderer->render($data['document'], $images, array_replace($data['options'], ['preset' => $data['options']['show_names'] ? 'names' : 'clean']))]]);
    }

    public function revoke(Request $request, string $id, string $publicationId)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1']);
        $this->publications->revoke($id, $publicationId, $input['expected_revision']);

        return response()->json(['message' => 'Ссылка отозвана.']);
    }
}
