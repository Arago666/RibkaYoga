<?php

namespace App\Modules\Practices\PublicApi;

interface RecentAsanas
{
    /** Active practice usage in the current workspace, newest documents first. */
    public function ids(): array;
}
