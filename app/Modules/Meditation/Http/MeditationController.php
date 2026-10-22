<?php

namespace App\Modules\Meditation\Http;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\PublicApi\AudioStore;
use App\Modules\Meditation\Models\MeditationSession;
use App\Modules\Meditation\Models\MeditationSettings;
use App\Modules\Meditation\Services\SessionTimer;
use App\Modules\Meditation\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeditationController
{
    public function __construct(private WorkspaceContext $workspace, private SessionTimer $timer, private AudioStore $audio) {}

    public function settings(Request $request): JsonResponse
    {
        $input = $request->validate(['timezone' => ['sometimes', 'timezone', 'max:80']]);
        $settings = $this->personalSettings($input['timezone'] ?? 'UTC');

        return response()->json(['data' => ['revision' => $settings->revision, 'settings' => [...Settings::defaults(), ...$settings->settings]], 'audio_limits' => $this->audio->limits()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'settings' => ['required', 'array']]);
        $input = Settings::validate($data['settings']);
        if (! empty($input['audio_asset_id'])) {
            $this->audio->get($input['audio_asset_id']);
        }
        $this->personalSettings();
        $settings = DB::transaction(function () use ($data, $input) {
            $record = $this->settingsQuery()->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->revision === (int) $data['expected_revision'], 409, 'Настройки изменены в другом окне. Обновите страницу или сохраните после сверки.');
            $record->settings = [...Settings::defaults(), ...$record->settings, ...$input];
            $record->revision++;
            $record->save();

            return $record;
        });

        return response()->json(['data' => ['revision' => $settings->revision, 'settings' => $settings->settings]]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'settings' => ['required', 'array']]);
        $personal = $this->personalSettings();
        $settings = [...Settings::defaults(), ...$personal->settings, ...Settings::validate($data['settings'])];
        if ($settings['audio_asset_id']) {
            $this->audio->get($settings['audio_asset_id']);
        }
        $session = $this->timer->start($data['id'], $settings);

        return response()->json(['data' => $this->timer->data($session)], 201);
    }

    public function active(): JsonResponse
    {
        $session = $this->timer->query()->whereIn('status', ['running', 'paused'])->latest('started_at')->first();

        return response()->json(['data' => $session ? $this->timer->data($session) : null]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->timer->data($this->timer->find($id))]);
    }

    public function command(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['heartbeat', 'pause', 'resume', 'complete', 'interrupt'])], 'expected_revision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->timer->data($this->timer->command($id, $data['action'], $data['expected_revision']))]);
    }

    public function history(Request $request): JsonResponse
    {
        [$input, $query] = $this->historyQuery($request);
        $page = (clone $query)->orderByDesc('started_at')->orderBy('id')->paginate(20);
        $days = [];
        $totalMs = 0;
        $completed = 0;
        $interrupted = 0;
        foreach ((clone $query)->orderBy('id')->cursor() as $session) {
            $day = $session->started_at->setTimezone($input['timezone'])->format('Y-m-d');
            $days[$day] = ($days[$day] ?? 0) + $session->active_ms;
            $totalMs += $session->active_ms;
            $session->status === 'completed' ? $completed++ : $interrupted++;
        }
        ksort($days);

        return response()->json([
            'data' => $page->getCollection()->map(fn (MeditationSession $session) => $this->timer->data($session))->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'summary' => ['active_ms' => $totalMs, 'completed' => $completed, 'interrupted' => $interrupted, 'days' => $days, 'timezone' => $input['timezone']],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$input, $query] = $this->historyQuery($request);

        return response()->streamDownload(function () use ($input, $query) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'Начало UTC', 'Начало местное', 'Часовой пояс', 'Тип', 'Статус', 'Активные секунды'], escape: '');
            foreach ($query->orderBy('started_at')->cursor() as $session) {
                fputcsv($stream, [$session->id, $session->started_at->toISOString(), $session->started_at->setTimezone($input['timezone'])->format('Y-m-d H:i:s'), $input['timezone'], $session->kind, $session->status, round($session->active_ms / 1000, 3)], escape: '');
            }
            fclose($stream);
        }, 'meditation-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function historyQuery(Request $request): array
    {
        $input = $request->validate(['timezone' => ['sometimes', 'timezone', 'max:80'], 'period' => ['sometimes', Rule::in(['1', '7', '30', '180', 'all'])], 'page' => ['sometimes', 'integer', 'min:1']]);
        $input['timezone'] ??= $this->personalSettings()->settings['timezone'] ?? 'UTC';
        $query = $this->timer->query()->whereIn('status', ['completed', 'interrupted']);
        if (($input['period'] ?? '30') !== 'all') {
            $start = CarbonImmutable::now($input['timezone'])->startOfDay()->subDays((int) ($input['period'] ?? '30') - 1)->utc();
            $query->where('started_at', '>=', $start);
        }

        return [$input, $query];
    }

    private function personalSettings(string $initialTimezone = 'UTC'): MeditationSettings
    {
        return $this->settingsQuery()->firstOrCreate(['workspace_id' => $this->workspace->id(), 'owner_id' => $this->workspace->userId()], ['settings' => [...Settings::defaults(), 'timezone' => $initialTimezone], 'revision' => 1]);
    }

    private function settingsQuery(): Builder
    {
        return MeditationSettings::where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId());
    }
}
