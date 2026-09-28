<?php

namespace App\Modules\Media\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\Models\Asset;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateAssetStore implements AssetStore
{
    /** Only fresh assets made by this operation may be discarded. */
    private array $pending = [];

    public function __construct(private WorkspaceContext $workspace) {}

    public function store(UploadedFile $file): array
    {
        $maxBytes = (int) config('ribka.upload_max_kb', 10240) * 1024;
        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['image' => 'Изображение должно быть не больше 10 МБ.']);
        }
        $contents = @file_get_contents($file->getRealPath());
        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['image' => 'Не удалось прочитать загруженный файл. Повторите загрузку.']);
        }
        $info = @getimagesizefromstring($contents);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! $info || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || $info['mime'] !== $mime) {
            throw ValidationException::withMessages(['image' => 'Поддерживаются только изображения PNG, JPEG и WebP.']);
        }
        if ((int) config('ribka.upload_max_pixels', 25000000) < $info[0] * $info[1]) {
            throw ValidationException::withMessages(['image' => 'Изображение превышает 25 мегапикселей.']);
        }
        $source = @imagecreatefromstring($contents);
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'Не удалось прочитать изображение.']);
        }
        $id = (string) Str::uuid();
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
        $originalPath = 'media/'.$this->workspace->id().'/'.$id.'.'.$extension;
        $thumbnailPath = 'media/'.$this->workspace->id().'/'.$id.'-thumb.webp';
        $ratio = min(1, 360 / max($info[0], $info[1]));
        $thumb = imagecreatetruecolor(max(1, (int) round($info[0] * $ratio)), max(1, (int) round($info[1] * $ratio)));
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 255, 255, 255, 127));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $info[0], $info[1]);
        ob_start();
        imagewebp($thumb, null, 85);
        $thumbnail = ob_get_clean();
        imagedestroy($thumb);
        imagedestroy($source);
        if (! is_string($thumbnail) || $thumbnail === '') {
            throw ValidationException::withMessages(['image' => 'Не удалось создать миниатюру изображения.']);
        }
        $disk = Storage::disk('local');
        try {
            if (! $disk->put($originalPath, $contents) || ! $disk->put($thumbnailPath, $thumbnail)) {
                throw new \RuntimeException('Не удалось сохранить изображение.');
            }
            $asset = Asset::create([
                'id' => $id, 'workspace_id' => $this->workspace->id(), 'uploaded_by' => $this->workspace->userId(),
                'disk' => 'local', 'original_path' => $originalPath, 'thumbnail_path' => $thumbnailPath,
                'mime' => $mime, 'bytes' => strlen($contents), 'width' => $info[0], 'height' => $info[1],
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            ]);
            $this->pending[$id] = true;

            return $this->data($asset);
        } catch (\Throwable $error) {
            $disk->delete([$originalPath, $thumbnailPath]);
            throw $error;
        }
    }

    public function get(string $id): array
    {
        return $this->data($this->find($id));
    }

    public function getMany(array $ids): array
    {
        foreach ($ids as $id) {
            abort_unless(is_string($id) && Str::isUuid($id), 404);
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        $assets = Asset::where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->get();
        abort_unless($assets->count() === count($ids), 404);

        return $assets->mapWithKeys(fn (Asset $asset) => [$asset->id => $this->data($asset)])->all();
    }

    public function discardUnattached(string $id): void
    {
        if (! isset($this->pending[$id])) {
            return;
        }
        $asset = $this->find($id);
        try {
            // Restrictive foreign keys protect attached catalog and practice images.
            $asset->delete();
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23503', '23000'], true)) {
                return;
            }
            throw $error;
        }
        Storage::disk($asset->disk)->delete([$asset->original_path, $asset->thumbnail_path]);
        unset($this->pending[$id]);
    }

    public function find(string $id): Asset
    {
        abort_unless(Str::isUuid($id), 404);

        return Asset::where('workspace_id', $this->workspace->id())->findOrFail($id);
    }

    private function data(Asset $asset): array
    {
        return [
            'id' => $asset->id, 'image_url' => '/media/'.$asset->id, 'thumbnail_url' => '/media/'.$asset->id.'?variant=thumbnail',
            'mime' => $asset->mime, 'width' => $asset->width, 'height' => $asset->height,
            'bytes' => $asset->bytes, 'original_filename' => $asset->original_filename,
        ];
    }
}
