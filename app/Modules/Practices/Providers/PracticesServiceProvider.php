<?php

namespace App\Modules\Practices\Providers;

use App\Modules\Practices\Console\ImportPracticeSheet;
use App\Modules\Practices\PublicApi\RecentAsanas;
use App\Modules\Practices\Services\PracticeUsage;
use Illuminate\Support\ServiceProvider;

class PracticesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecentAsanas::class, PracticeUsage::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportPracticeSheet::class]);
        }
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadRoutesFrom(__DIR__.'/../sharing-routes.php');
        $this->loadRoutesFrom(__DIR__.'/../illustration-routes.php');
    }
}
