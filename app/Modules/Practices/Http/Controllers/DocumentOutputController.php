<?php

namespace App\Modules\Practices\Http\Controllers;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Practices\Services\DocumentDelivery;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentOutputController
{
    public function __construct(private DocumentDelivery $delivery, private WorkspaceContext $workspace) {}

    public function sheet(Request $request, string $id)
    {
        return response($this->delivery->html($id, $this->options($request)))->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
    }

    public function pdf(Request $request, string $id)
    {
        $options = $this->options($request);
        $document = $this->delivery->document($id);
        $html = $this->delivery->html($id, $options, true);
        $temp = storage_path('framework/cache/pdf');
        if (! is_dir($temp)) {
            mkdir($temp, 0750, true);
        }
        $config = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'tempDir' => $temp, 'fontCache' => $temp, 'chroot' => [$temp, base_path('vendor/dompdf/dompdf/lib/fonts')]]);
        $pdf = new Dompdf($config);
        $pdf->setPaper('A4', $options['orientation']);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $filename = preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '-', $document['title']).'.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="practice.pdf"; filename*=UTF-8\'\''.rawurlencode($filename), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preferences()
    {
        $row = $this->preferencesQuery()->first();

        return response()->json(['data' => ['revision' => $row ? (int) $row->revision : null, 'preferences' => $row ? json_decode($row->preferences, true) : ['preset' => 'clean', 'show_names' => false, 'orientation' => 'landscape']]]);
    }

    public function savePreferences(Request $request)
    {
        $options = $this->options($request);
        $input = $request->validate(['expected_revision' => 'nullable|integer|min:1']);
        DB::transaction(function () use ($options, $input) {
            $row = $this->preferencesQuery()->lockForUpdate()->first();
            abort_if($row && (int) $row->revision !== ($input['expected_revision'] ?? null), 409, 'Настройки изменились в другом окне. Обновите страницу.');
            if ($row) {
                $this->preferencesQuery()->update(['preferences' => json_encode($options, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1, 'updated_at' => now()]);
            } else {
                $this->preferencesQuery()->insert(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'preferences' => json_encode($options, JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $this->preferences();
    }

    private function preferencesQuery()
    {
        return DB::table('practice_output_preferences')->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
    }

    private function options(Request $request): array
    {
        $input = $request->validate(['preset' => 'sometimes|in:clean,names,teach', 'orientation' => 'sometimes|in:portrait,landscape', 'show_names' => 'sometimes|boolean']);
        $preset = $input['preset'] ?? 'clean';

        return ['preset' => $preset, 'orientation' => $input['orientation'] ?? 'landscape', 'show_names' => $preset === 'clean' ? false : (bool) ($input['show_names'] ?? true)];
    }
}
