<?php

namespace App\Modules\Media\PublicApi;

use Illuminate\Http\UploadedFile;

/** Personal audio: ownership is always resolved from the authenticated session. */
interface AudioStore
{
    public function limits(): array;

    public function list(bool $archived = false): array;

    public function store(UploadedFile $file, string $title): array;

    public function get(string $id, bool $allowArchived = false): array;

    public function update(string $id, int $revision, ?string $title, ?bool $archived): array;

    /** Authorized local path; callers must not publish it in responses. */
    public function stream(string $id): array;
}
