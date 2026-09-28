<?php

namespace App\Modules\Media;

use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Media\Services\PrivateAssetStore;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AssetStore::class, PrivateAssetStore::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth'])->group(__DIR__.'/routes.php');
    }
}
