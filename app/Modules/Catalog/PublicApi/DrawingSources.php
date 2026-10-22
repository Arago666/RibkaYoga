<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Catalog\Services\DrawingSource;

class DrawingSources
{
    public function __construct(private DrawingSource $validator) {}

    public function validate(mixed $source, bool $allowEmpty = false): ?array
    {
        return $this->validator->validate($source, $allowEmpty);
    }
}
