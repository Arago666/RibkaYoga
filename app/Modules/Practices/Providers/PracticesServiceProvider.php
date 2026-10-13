<?php

namespace App\Modules\Practices\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Practices\PublicApi\RecentAsanas;
use App\Modules\Practices\Services\PracticeUsage;

class PracticesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecentAsanas::class, PracticeUsage::class);
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadRoutesFrom(__DIR__.'/../sharing-routes.php');
    }
}
