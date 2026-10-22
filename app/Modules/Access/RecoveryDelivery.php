<?php

namespace App\Modules\Access;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A production log is not email delivery. Local development keeps its mail log. */
class RecoveryDelivery
{
    public static function available(): bool
    {
        return app()->isLocal() || ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('POST') && $request->is('forgot-password') && ! self::available()) {
            throw ValidationException::withMessages(['email' => 'Отправка писем пока не подключена. Для восстановления доступа обратитесь к администратору.']);
        }

        return $next($request);
    }
}
