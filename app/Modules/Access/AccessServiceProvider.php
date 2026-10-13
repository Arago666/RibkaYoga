<?php

namespace App\Modules\Access;

use App\Modules\Access\Console\CreateMember;
use App\Modules\Access\Console\CreateWorkspace;
use App\Modules\Access\Console\ResetPassword;
use App\Modules\Access\Console\SetAdministrator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth'])->group(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([CreateWorkspace::class, CreateMember::class, ResetPassword::class, SetAdministrator::class]);
        }
    }
}
