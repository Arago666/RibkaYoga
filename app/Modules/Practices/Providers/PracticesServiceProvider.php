<?php

namespace App\Modules\Practices\Providers;

use Illuminate\Support\ServiceProvider;

class PracticesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
