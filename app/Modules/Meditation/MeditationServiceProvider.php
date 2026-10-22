<?php

namespace App\Modules\Meditation;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MeditationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth'])->group(__DIR__.'/routes.php');
    }
}
