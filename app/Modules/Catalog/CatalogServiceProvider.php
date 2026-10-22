<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Console\CatalogJson;
use App\Modules\Catalog\Console\ClassifyAsanas;
use App\Modules\Catalog\Console\EnrichReferences;
use App\Modules\Catalog\Console\ImportAsanas;
use App\Modules\Catalog\Console\ImportDrawingPack;
use App\Modules\Catalog\Console\PinNativeSources;
use App\Modules\Catalog\Console\SeedTaxonomy;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Catalog\Services\CatalogReader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AsanaReader::class, CatalogReader::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnrichReferences::class, PinNativeSources::class, SeedTaxonomy::class, ClassifyAsanas::class, ImportAsanas::class, CatalogJson::class, ImportDrawingPack::class]);
        }
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        Route::middleware(['web', 'auth'])->group(__DIR__.'/routes.php');
    }
}
