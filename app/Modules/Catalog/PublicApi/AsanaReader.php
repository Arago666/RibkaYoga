<?php

namespace App\Modules\Catalog\PublicApi;

interface AsanaReader
{
    /** Returns workspace-owned cards keyed by ID; inaccessible/missing IDs are rejected. */
    public function findMany(array $ids, bool $includeArchived = false): array;

    /** Returns the IDs of exact active name matches in this workspace, keyed by the requested names. */
    public function activeIdsByName(array $names): array;
}
