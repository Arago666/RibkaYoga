<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FigureComparisonController
{
    private const DIRECTORY = 'catalog-comparisons/bold-20261105';

    public function __construct(private WorkspaceContext $workspace) {}

    public function available(): JsonResponse
    {
        $manifest = $this->readManifest();

        return response()->json(['available' => ($manifest['workspace_id'] ?? null) === $this->workspace->id()]);
    }

    public function show(): Response
    {
        $manifest = $this->authorizedManifest();
        $items = array_map(function (array $item): array {
            $index = $item['index'];
            $item['before'] = '/figure-comparison/images/'.$index.'/original?v='.substr($item['before_sha'], 0, 16);
            $item['photo'] = isset($item['photo_sha'])
                ? '/figure-comparison/images/'.$index.'/realistic?v='.substr($item['photo_sha'], 0, 16)
                : null;
            unset($item['files'], $item['photo_sha']);

            return $item;
        }, $manifest['items']);
        $json = json_encode($items, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $html = strtr(file_get_contents(resource_path('views/catalog/figure-comparison.html')), [
            '__DATA__' => $json,
            '__COUNT__' => (string) count($items),
            '__PHOTO_COUNT__' => (string) count(array_filter($items, fn (array $item): bool => $item['photo'] !== null)),
            '__COLLECTION__' => $manifest['collection'],
            '__SOURCE_SET__' => $manifest['source_set'],
            '__OLD_COLLECTION__' => $manifest['old_collection'],
        ]);

        return response($html)->header('Cache-Control', 'private, no-store');
    }

    public function image(string $index, string $variant): BinaryFileResponse
    {
        $manifest = $this->authorizedManifest();
        $item = collect($manifest['items'])->firstWhere('index', (int) $index);
        abort_unless($item && in_array($variant, ['original', 'realistic'], true), 404);
        $relative = $item['files'][$variant] ?? null;
        abort_unless(is_string($relative) && preg_match('~\A(?:originals|realistic)/[0-9]{3}\.png\z~D', $relative), 404);
        $disk = Storage::disk('local');
        $path = self::DIRECTORY.'/'.$relative;
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizedManifest(): array
    {
        $manifest = $this->readManifest();
        abort_unless(($manifest['workspace_id'] ?? null) === $this->workspace->id(), 404);

        return $manifest;
    }

    private function readManifest(): array
    {
        $disk = Storage::disk('local');
        $path = self::DIRECTORY.'/manifest.json';
        if (! $disk->exists($path)) {
            return [];
        }

        return json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
