<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Access\Actions\RegisterUser;
use App\Modules\Access\Actions\ResetUserPassword;
use App\Modules\Access\Models\WorkspaceMember;
use App\Modules\Access\RecoveryDelivery;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('access/Login', ['status' => session('status')]));
        Fortify::registerView(fn () => Inertia::render('access/Register', ['trialDays' => max(0, (int) config('billing.trial_days', 14))]));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('access/ForgotPassword', ['status' => session('status'), 'localMail' => app()->isLocal() && config('mail.default') === 'log', 'recoveryAvailable' => RecoveryDelivery::available()]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('access/ResetPassword', ['token' => $request->route('token'), 'email' => $request->query('email', '')]));
        Fortify::createUsersUsing(RegisterUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('login', Str::lower(trim((string) $request->input('login'))))->first();
            if ($user && Hash::check((string) $request->input('password'), $user->password)
                && WorkspaceMember::where('user_id', $user->id)->exists()) {
                return $user;
            }

            return null;
        });
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower(trim((string) $request->input('login'))).'|'.$request->ip()));
        RateLimiter::for('access', fn (Request $request) => Limit::perMinute($request->isMethod('post') ? 6 : 60)
            ->by($request->method().'|'.$request->path().'|'.$request->ip()));
    }
}
