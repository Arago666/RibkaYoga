<?php

namespace App\Modules\Access;

use App\Modules\Access\Console\CreateMember;
use App\Modules\Access\Console\CreateWorkspace;
use App\Modules\Access\Console\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateWorkspace::class, CreateMember::class, ResetPassword::class]);
        }
    }
}
