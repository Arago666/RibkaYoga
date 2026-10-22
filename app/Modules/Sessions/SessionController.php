<?php

namespace App\Modules\Sessions;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Practices\PublicApi\ExecutionDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SessionController
{
    public function __construct(private WorkspaceContext $workspace, private ExecutionDocuments $documents) {}

    private function owned()
    {
        return DB::table('practice_execution_sessions')->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
    }

    private function data(object $row): array
    {
        return [...(array) $row, 'plan' => json_decode($row->plan, true), 'progress' => json_decode($row->progress, true)];
    }

    public function plan(string $id)
    {
        return response()->json(['data' => $this->documents->plan($id)]);
    }

    public function show(string $id)
    {
        $row = $this->owned()->where('id', $id)->first();
        abort_unless($row, 404);

        return response()->json(['data' => $this->data($row)]);
    }

    public function store(Request $request)
    {
        $input = $request->validate(['id' => 'required|uuid', 'document_id' => 'required|uuid', 'expected_revision' => 'required|integer|min:1', 'mode' => ['required', Rule::in(['teach', 'practice'])], 'timezone' => 'required|timezone']);
        $result = DB::transaction(function () use ($input) {
            $existing = DB::table('practice_execution_sessions')->where('id', $input['id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === $this->workspace->userId() && $existing->workspace_id === $this->workspace->id() && $existing->document_id === $input['document_id'], 409);

                return $this->data($existing);
            }
            $plan = $this->documents->plan($input['document_id']);
            abort_unless((int) $plan['revision'] === (int) $input['expected_revision'], 409, 'Практика изменилась. Откройте обновлённый план.');
            abort_unless(count($plan['steps']) > 0, 422, 'В практике пока нет шагов.');
            DB::table('practice_execution_sessions')->insert(['id' => $input['id'], 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'document_id' => $input['document_id'], 'title' => $plan['title'], 'mode' => $input['mode'], 'status' => 'running', 'timezone' => $input['timezone'], 'plan' => json_encode($plan, JSON_THROW_ON_ERROR), 'progress' => json_encode(['step' => 0, 'seconds_by_step' => [], 'completed' => []]), 'active_seconds' => 0, 'revision' => 1, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $this->data($this->owned()->where('id', $input['id'])->first());
        });

        return response()->json(['data' => $result], 201);
    }

    public function update(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'status' => ['required', Rule::in(['running', 'paused', 'completed', 'interrupted'])], 'active_seconds' => 'required|integer|min:0|max:86400', 'progress' => 'required|array:step,seconds_by_step,completed', 'progress.step' => 'required|integer|min:0|max:9999', 'progress.seconds_by_step' => 'present|array|list|max:10000', 'progress.seconds_by_step.*' => 'integer|min:0|max:86400', 'progress.completed' => 'present|array|list|max:10000', 'progress.completed.*' => 'integer|distinct|min:0|max:9999']);
        $row = DB::transaction(function () use ($id, $input) {
            $row = $this->owned()->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (in_array($row->status, ['completed', 'interrupted'], true)) {
                return $this->data($row);
            }
            abort_unless((int) $row->revision === (int) $input['expected_revision'], 409, 'Сессия изменена в другом окне.');
            $plan = json_decode($row->plan, true);
            $count = count($plan['steps']);
            abort_unless($input['progress']['step'] < $count && count($input['progress']['seconds_by_step']) <= $count && (empty($input['progress']['completed']) || max($input['progress']['completed']) < $count), 422);
            $wall = max(0, now()->timestamp - Carbon::parse($row->started_at)->timestamp);
            abort_unless($input['active_seconds'] >= $row->active_seconds && $input['active_seconds'] <= $wall + 3 && array_sum($input['progress']['seconds_by_step']) <= $input['active_seconds'] + $count, 422, 'Некорректное время сессии.');
            $done = in_array($input['status'], ['completed', 'interrupted'], true);
            $this->owned()->where('id', $id)->update(['status' => $input['status'], 'active_seconds' => $input['active_seconds'], 'progress' => json_encode($input['progress'], JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1, 'ended_at' => $done ? now() : null, 'updated_at' => now()]);

            return $this->data($this->owned()->where('id', $id)->first());
        });

        return response()->json(['data' => $row]);
    }

    public function index(Request $request)
    {
        $input = $request->validate(['days' => 'nullable|integer|min:1|max:3650', 'timezone' => 'nullable|timezone']);
        $tz = $input['timezone'] ?? 'UTC';
        $query = $this->owned();
        if (! empty($input['days'])) {
            $query->where('started_at', '>=', Carbon::now($tz)->startOfDay()->subDays((int) $input['days'] - 1)->utc());
        }
        $rows = (clone $query)->orderByDesc('started_at')->limit(1000)->get();
        $calendar = [];
        $anatomy = [];
        $unclassified = 0;
        foreach ((clone $query)->orderBy('id')->cursor() as $row) {
            $date = Carbon::parse($row->started_at)->timezone($tz)->format('Y-m-d');
            $calendar[$date] = ($calendar[$date] ?? 0) + (int) $row->active_seconds;
            $plan = json_decode($row->plan, true);
            $progress = json_decode($row->progress, true);
            foreach ($plan['steps'] as $i => $step) {
                $seconds = (int) ($progress['seconds_by_step'][$i] ?? 0);
                if (! $step['anatomy']) {
                    $unclassified += $seconds;
                }
                foreach ($step['anatomy'] as $zone) {
                    $key = $zone['key'] ?? $zone['id'] ?? $zone['name'];
                    $anatomy[$key] ??= ['name' => $zone['name'], 'seconds' => 0];
                    $anatomy[$key]['seconds'] += $seconds;
                }
            }
        }

        return response()->json(['data' => $rows->map(fn ($row) => $this->data($row)), 'summary' => ['sessions' => (clone $query)->count(), 'completed' => (clone $query)->where('status', 'completed')->count(), 'seconds' => (clone $query)->sum('active_seconds'), 'calendar' => $calendar, 'anatomy' => array_values($anatomy), 'unclassified_seconds' => $unclassified, 'timezone' => $tz, 'limit' => 1000]]);
    }

    public function export()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Название', 'Режим', 'Статус', 'Начало UTC', 'Активные секунды', 'Часовой пояс'], ',', '"', '');
            foreach ($this->owned()->orderBy('started_at')->cursor() as $row) {
                $title = preg_match('/^[=+@\-]/u', $row->title) ? "'".$row->title : $row->title;
                fputcsv($out, [$title, $row->mode, $row->status, $row->started_at, $row->active_seconds, $row->timezone], ',', '"', '');
            }
            fclose($out);
        }, 'ribkayoga-practice-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
