<?php

namespace App\Modules\Practices\Services;

use Illuminate\Http\Response;

class SheetResponse
{
    public static function html(string $html, bool $embeddedImages = false): Response
    {
        $images = $embeddedImages ? 'data:' : "'self' data:";

        // Telegram Web embeds the app, which in turn embeds this printable sheet.
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self' https://web.telegram.org",
        ]);
    }
}
