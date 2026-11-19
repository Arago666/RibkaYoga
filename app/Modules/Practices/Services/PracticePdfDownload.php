<?php

namespace App\Modules\Practices\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Telegram downloads through the OS, outside the signed-in WebView. */
class PracticePdfDownload
{
    private const LIFETIME = 180;

    public function issue(string $pdf, string $filename): array
    {
        $directory = $this->directory();
        File::ensureDirectoryExists($directory, 0700, true);
        $now = time();
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            $created = @filemtime($file->getPathname());
            if ($created !== false && $file->getExtension() === 'pdf' && $created < $now - self::LIFETIME * 2) {
                File::delete($file->getPathname());
            }
        }

        $token = bin2hex(random_bytes(32));
        $path = $directory.'/'.$token.'.pdf';
        if (File::put($path, $pdf) !== strlen($pdf)) {
            File::delete($path);
            abort(503, 'Не удалось подготовить PDF.');
        }
        $title = preg_replace('/[:*?<>|]+/u', '-', substr($filename, 0, -4));
        $filename = (trim(mb_strcut($title, 0, 200, 'UTF-8'), ' .') ?: 'Практика').'.pdf';
        $expires = now()->addSeconds(self::LIFETIME);

        return [
            'url' => route('practice-pdf.download', ['token' => $token]),
            'filename' => $filename,
            'expires_at' => $expires->toIso8601String(),
            'expires_in' => self::LIFETIME,
        ];
    }

    public function download(string $token): BinaryFileResponse
    {
        $path = $this->directory().'/'.$token.'.pdf';
        clearstatcache(true, $path);
        $created = @filemtime($path);
        abort_unless($created !== false && time() < $created + self::LIFETIME, 404);

        return response()->download($path, 'practice.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'Access-Control-Allow-Origin' => 'https://web.telegram.org',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ])->setPrivate();
    }

    private function directory(): string
    {
        return storage_path('app/private/telegram-practice-pdf');
    }
}
