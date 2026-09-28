<?php

namespace App\Modules\Media\PublicApi;

use Illuminate\Http\UploadedFile;

interface AssetStore
{
    public function store(UploadedFile $file): array;

    public function get(string $id): array;

    /** @return array<string, array> */
    public function getMany(array $ids): array;

    public function discardUnattached(string $id): void;
}
