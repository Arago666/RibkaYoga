<?php

namespace App\Modules\Catalog\Illustrations;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class IllustrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WarmVolumeVariants::class]);
        }
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/illustration-settings', fn () => Inertia::render('catalog/Illustrations'))->name('illustration-settings');
            Route::get('/api/illustration-styles', [IllustrationController::class, 'state']);
            Route::put('/api/illustration-styles/preferences', [IllustrationController::class, 'preferences']);
            Route::post('/api/illustration-styles/prepare', [IllustrationController::class, 'prepare'])->middleware('throttle:60,1');
        });
    }
}
