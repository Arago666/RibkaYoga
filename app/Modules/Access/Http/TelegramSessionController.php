<?php

namespace App\Modules\Access\Http;

use App\Models\User;
use App\Modules\Access\Actions\CreatePersonalWorkspace;
use App\Modules\Access\Models\TelegramIdentity;
use App\Modules\Access\Models\WorkspaceMember;
use App\Modules\Access\TelegramIdentityVerifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramSessionController
{
    public function __invoke(Request $request, TelegramIdentityVerifier $verifier): array
    {
        $data = $request->validate(['init_data' => ['required', 'string', 'max:16384']]);
        $profile = $verifier->verify($data['init_data']);
        $nonce = $request->session()->get('telegram_login_nonce', Str::random(40));
        $request->session()->put('telegram_login_nonce', $nonce);
        $proofKey = 'telegram:proof:'.$profile['proof'];
        Cache::add($proofKey, $nonce, now()->addMinutes(16));
        abort_unless(hash_equals((string) Cache::get($proofKey), $nonce), 403, 'Этот запуск уже использован. Откройте Mini App заново.');

        try {
            return Cache::lock('telegram:identity:'.$profile['bot_id'].':'.$profile['telegram_id'], 120)->block(5, function () use ($request, $profile) {
                $identity = TelegramIdentity::where('bot_id', $profile['bot_id'])->where('telegram_id', $profile['telegram_id'])->first();
                if (! $identity) {
                    // The stable ID is authoritative. A matching @username or site login never links accounts.
                    $identity = DB::transaction(function () use ($profile) {
                        $login = mb_strtolower($profile['username'] ?: 'tg_'.$profile['telegram_id']);
                        if (! preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $login)) {
                            $login = 'tg_'.$profile['telegram_id'];
                        }
                        if (User::where('login', $login)->exists()) {
                            $login = 'tg_'.$profile['telegram_id'].'_'.Str::lower(Str::random(8));
                        }
                        $user = new User;
                        $user->forceFill(['name' => $profile['name'], 'login' => $login, 'email' => null,
                            'password' => Str::random(80), 'password_login_enabled' => false])->save();
                        app(CreatePersonalWorkspace::class)->create($user);

                        return TelegramIdentity::create(['user_id' => $user->id, 'bot_id' => $profile['bot_id'],
                            'telegram_id' => $profile['telegram_id'], 'username' => $profile['username']]);
                    });
                }
                $user = User::findOrFail($identity->user_id);
                abort_unless(WorkspaceMember::where('user_id', $user->id)->whereIn('role', ['owner', 'editor'])->exists(), 403, 'Нет доступа к кабинету.');
                $identity->update(['username' => $profile['username']]);
                Auth::guard()->logoutCurrentDevice();
                Auth::login($user);
                $request->session()->regenerate();
                $request->session()->regenerateToken();
                $request->session()->put('mini_app', true);
                $request->session()->forget('url.intended');

                return ['status' => 'authenticated', 'redirect' => '/dashboard'];
            });
        } catch (LockTimeoutException) {
            abort(429, 'Кабинет ещё открывается в другом окне. Повторите вход через несколько секунд.');
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Данные кабинета обновились. Повторите вход.');
        }
    }
}
