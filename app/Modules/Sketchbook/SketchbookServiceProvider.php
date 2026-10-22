<?php

namespace App\Modules\Sketchbook;

use Illuminate\Support\ServiceProvider;

class SketchbookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
