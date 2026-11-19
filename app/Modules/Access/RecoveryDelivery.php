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

    public static function supportUrl(): ?string
    {
        $url = trim((string) config('auth.recovery_support_url'));
        if (str_starts_with($url, 'mailto:')) {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) ? $url : null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('POST') && $request->is('forgot-password') && ! self::available()) {
            throw ValidationException::withMessages(['email' => 'Восстановление по почте недоступно. Откройте страницу восстановления, чтобы посмотреть доступные варианты.']);
        }

        $response = $next($request);
        if ($request->is('reset-password/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
