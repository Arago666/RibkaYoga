<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Practices\Services\PublicPublications;

class PublicationMediaAccess
{
    public function __construct(private PublicPublications $publications) {}

    public function authorize(string $token, string $assetId): string
    {
        $publication = $this->publications->resolve($token);
        abort_unless(in_array(strtolower($assetId), json_decode($publication->asset_ids, true, 512, JSON_THROW_ON_ERROR), true), 404);

        return $publication->workspace_id;
    }
}
