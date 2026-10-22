<?php

namespace App\Modules\Meditation\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Meditation\Models\MeditationSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionTimer
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function start(string $id, array $settings): MeditationSession
    {
        return DB::transaction(function () use ($id, $settings) {
            // Personal settings is a Meditation-owned stable lock, created by controller.
            DB::table('meditation_settings')->where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId())->lockForUpdate()->first();
            $existing = $this->query()->find($id);
            if ($existing) {
                abort_unless($existing->settings === $settings, 409, 'Этот запуск уже существует с другими настройками.');

                return $existing;
            }
            $other = $this->query()->whereIn('status', ['running', 'paused'])->lockForUpdate()->first();
            if ($other) {
                throw ValidationException::withMessages(['session' => 'У вас уже есть незавершённая медитация. Вернитесь к ней или завершите её.']);
            }

            return MeditationSession::create([
                'id' => $id, 'workspace_id' => $this->workspace->id(), 'owner_id' => $this->workspace->userId(),
                'kind' => $settings['kind'], 'status' => 'running', 'timezone' => $settings['timezone'],
                'settings' => $settings, 'audio_asset_id' => $settings['audio_asset_id'],
                'active_ms' => 0, 'revision' => 1,
                'started_at' => now(), 'active_since' => now(), 'lease_until' => now()->addSeconds(30),
            ]);
        });
    }

    public function command(string $id, string $action, int $revision): MeditationSession
    {
        return DB::transaction(function () use ($id, $action, $revision) {
            $session = $this->query()->lockForUpdate()->findOrFail($id);
            if (in_array($session->status, ['completed', 'interrupted'], true)) {
                return $session; // Repeated finish is idempotent, never adds time twice.
            }
            abort_unless($session->revision === $revision, 409, 'Сессия изменена в другом окне. Обновите её перед продолжением.');
            $this->checkpoint($session);
            if ($session->status !== 'completed') {
                if ($action === 'resume') {
                    $session->status = 'running';
                    $session->active_since = now();
                    $session->lease_until = now()->addSeconds(30);
                } elseif ($action === 'heartbeat' && $session->status === 'running') {
                    $session->active_since = now();
                    $session->lease_until = now()->addSeconds(30);
                } elseif (in_array($action, ['pause', 'complete', 'interrupt'], true)) {
                    $session->status = match ($action) {
                        'complete' => 'completed', 'interrupt' => 'interrupted', default => 'paused'
                    };
                    $session->active_since = null;
                    $session->lease_until = null;
                    if ($session->status !== 'paused') {
                        $session->ended_at = now();
                    }
                }
            }
            $session->revision++;
            $session->save();

            return $session;
        });
    }

    public function find(string $id): MeditationSession
    {
        return $this->query()->findOrFail($id);
    }

    public function query(): Builder
    {
        return MeditationSession::where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId());
    }

    public function data(MeditationSession $session): array
    {
        // GET never extends the lease or counts unattended time after a disconnect.
        $copy = clone $session;
        $this->checkpoint($copy);

        return ['id' => $copy->id, 'kind' => $copy->kind, 'status' => $copy->status, 'timezone' => $copy->timezone, 'settings' => $copy->settings, 'active_ms' => $copy->active_ms, 'revision' => $copy->revision, 'started_at' => $copy->started_at->toISOString(), 'ended_at' => $copy->ended_at?->toISOString(), 'lease_remaining_ms' => $copy->status === 'running' ? max(0, (int) now()->diffInMilliseconds($copy->lease_until, false)) : 0];
    }

    private function checkpoint(MeditationSession $session): void
    {
        if ($session->status !== 'running' || ! $session->active_since || ! $session->lease_until) {
            return;
        }
        $end = now()->min($session->lease_until);
        $session->active_ms += max(0, (int) $session->active_since->diffInMilliseconds($end, false));
        $session->active_since = $end;
        $duration = $session->settings['duration_seconds'];
        if ($duration !== null && $session->active_ms >= $duration * 1000) {
            $session->active_ms = $duration * 1000;
            $session->status = 'completed';
            $session->ended_at = $end;
            $session->active_since = null;
            $session->lease_until = null;
        } elseif (now()->greaterThanOrEqualTo($session->lease_until)) {
            $session->status = 'paused';
            $session->active_since = null;
            $session->lease_until = null;
        }
    }
}
