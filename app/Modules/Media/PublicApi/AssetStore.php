<?php

namespace App\Modules\Media\PublicApi;

use Illuminate\Http\UploadedFile;

interface AssetStore
{
    public function store(UploadedFile $file): array;

    public function get(string $id): array;

    /** @return array<string, array> */
    public function getMany(array $ids): array;

    /** Workspace-owned original, encoded for portable JSON jobs. */
    public function originalData(string $id): array;

    /** Authorizes a concrete practice and asset, including a granted viewer. */
    public function forDocument(string $id, string $documentId): array;

    public function discardUnattached(string $id): void;
}
