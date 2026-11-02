<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Practices\Services\PracticeReviews;
use App\Modules\Practices\Services\ReviewSheetRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class ReviewOutputController
{
    public function __construct(private PracticeReviews $reviews, private ReviewSheetRenderer $renderer) {}

    public function sheet(Request $request, string $id)
    {
        [$review, $options] = $this->prepare($request, $id);
        $html = $this->renderer->render($review, $this->reviews->images($id, true), $options);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
    }

    public function pdf(Request $request, string $id)
    {
        [$review, $options] = $this->prepare($request, $id);
        $html = $this->renderer->render($review, $this->reviews->images($id, true), $options);
        $temp = storage_path('framework/cache/pdf');
        if (! is_dir($temp)) {
            mkdir($temp, 0750, true);
        }
        $config = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'tempDir' => $temp, 'fontCache' => $temp, 'chroot' => [$temp, base_path('vendor/dompdf/dompdf/lib/fonts')]]);
        $pdf = new Dompdf($config);
        $pdf->setPaper('A4', $options['orientation']);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $filename = preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '-', $review['title']).'.pdf';
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => $disposition.'; filename="practice-review.pdf"; filename*=UTF-8\'\''.rawurlencode($filename), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function prepare(Request $request, string $id): array
    {
        $input = $request->validate(['orientation' => 'sometimes|in:portrait,landscape', 'show_names' => 'sometimes|boolean', 'inline' => 'sometimes|boolean', 'expected_revision' => 'sometimes|integer|min:1']);
        $review = $this->reviews->read($id);
        abort_if(isset($input['expected_revision']) && (int) $input['expected_revision'] !== $review['revision'], 409, 'Разбор изменился. Обновите предварительный просмотр.');

        return [$review, ['orientation' => $input['orientation'] ?? 'landscape', 'show_names' => (bool) ($input['show_names'] ?? false)]];
    }
}
