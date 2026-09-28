<?php

namespace App\Modules\Catalog\PublicApi;

interface AsanaReader
{
    /** Returns workspace-owned cards keyed by ID; inaccessible/missing IDs are rejected. */
    public function findMany(array $ids, bool $includeArchived = false): array;
}
