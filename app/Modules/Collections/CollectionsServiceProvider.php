<?php

namespace App\Modules\Collections;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CollectionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth'])->group(__DIR__.'/routes.php');
    }
}
