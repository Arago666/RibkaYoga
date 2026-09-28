<?php

use App\Modules\Access\AccessServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Collections\CollectionsServiceProvider;
use App\Modules\Media\MediaServiceProvider;
use App\Modules\Practices\Providers\PracticesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AccessServiceProvider::class,
    MediaServiceProvider::class,
    CatalogServiceProvider::class,
    CollectionsServiceProvider::class,
    PracticesServiceProvider::class,
];
