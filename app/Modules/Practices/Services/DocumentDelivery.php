<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\Actions\PracticeEditor;
use Illuminate\Support\Facades\DB;

class DocumentDelivery
{
    public function __construct(private WorkspaceContext $workspace, private PracticeEditor $editor, private AssetStore $assets, private SheetRenderer $renderer) {}

    public function document(string $id): array
    {
        return $this->editor->document($id);
    }

    public function snapshotRows(array $document): array
    {
        $ids = collect($document['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => array_column($row['cells'], 'card_snapshot_id')))->filter()->unique()->all();
        $rows = DB::table('practice_card_snapshots')->where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($rows->count() === count($ids), 422, 'Снимок изображения недоступен.');

        return $rows->all();
    }

    public function html(string $id, array $options, bool $embeddedImages = false): string
    {
        $document = $this->document($id);
        $snapshots = $this->snapshotRows($document);
        $images = [];
        $cache = [];
        $bytes = 0;
        foreach ($snapshots as $snapshot) {
            if (! isset($cache[$snapshot->image_asset_id])) {
                if ($embeddedImages) {
                    $asset = $this->assets->originalData($snapshot->image_asset_id);
                    $bytes += (int) $asset['bytes'];
                    abort_if($bytes > 48 * 1024 * 1024, 422, 'Изображения слишком велики для серверного PDF. Используйте печать браузера или уменьшите исходные файлы.');
                    $cache[$snapshot->image_asset_id] = $this->pdfImage($asset['data_url']);
                } else {
                    $cache[$snapshot->image_asset_id] = $this->assets->get($snapshot->image_asset_id)['image_url'];
                }
            }
            $images[$snapshot->id] = $cache[$snapshot->image_asset_id];
        }

        return $this->renderer->render($document, $images, $options);
    }

    private function pdfImage(string $dataUrl): string
    {
        $bytes = base64_decode(explode(',', $dataUrl, 2)[1] ?? '', true);
        abort_unless(is_string($bytes), 422, 'Изображение повреждено.');
        $image = @imagecreatefromstring($bytes);
        abort_unless($image, 422, 'Не удалось подготовить изображение.');
        $ratio = min(1, 420 / max(imagesx($image), imagesy($image)));
        $resized = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image));
        ob_start();
        imagepng($resized, null, 6);
        $encoded = ob_get_clean();
        unset($image, $resized);
        abort_unless(is_string($encoded) && $encoded !== '', 422);

        return 'data:image/png;base64,'.base64_encode($encoded);
    }
}
