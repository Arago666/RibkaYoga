<?php

use App\Modules\Access\AccessServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Catalog\Illustrations\IllustrationServiceProvider;
use App\Modules\Collections\CollectionsServiceProvider;
use App\Modules\Media\MediaServiceProvider;
use App\Modules\Meditation\MeditationServiceProvider;
use App\Modules\Practices\Providers\PracticesServiceProvider;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Modules\Sketchbook\SketchbookServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AccessServiceProvider::class,
    MediaServiceProvider::class,
    CatalogServiceProvider::class,
    IllustrationServiceProvider::class,
    CollectionsServiceProvider::class,
    PracticesServiceProvider::class,
    SessionsServiceProvider::class,
    SketchbookServiceProvider::class,
    MeditationServiceProvider::class,
];
