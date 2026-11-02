<?php

namespace App\Modules\Catalog\Illustrations;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IllustrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keep bookmarks useful while the removed representation has no generation endpoints.
        Route::middleware(['web', 'auth'])->get('/illustration-settings', fn () => redirect('/settings'))->name('illustration-settings');
    }
}
