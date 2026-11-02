<?php

namespace App\Modules\Sessions;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetronomePresetController
{
    public function __construct(private WorkspaceContext $workspace) {}

    private function owned()
    {
        return DB::table('practice_metronome_presets')->where('workspace_id', $this->workspace->id())
            ->where('user_id', $this->workspace->userId())->whereNull('archived_at');
    }

    private function data(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'settings' => json_decode($row->settings, true),
            'revision' => (int) $row->revision, 'updated_at' => $row->updated_at];
    }

    public function index()
    {
        return response()->json(['data' => $this->owned()->orderBy('name')->orderBy('id')->get()->map(fn ($row) => $this->data($row))->all()]);
    }

    private function input(Request $request, bool $updating): array
    {
        $input = $request->validate(MetronomeSettings::rules('settings.') + [
            'id' => [$updating ? 'sometimes' : 'required', 'uuid'],
            'name' => ['required', 'string', 'max:160'], 'settings' => ['required', 'array'],
            'expected_revision' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
        $name = trim($input['name']);
        abort_unless($name !== '', 422, 'Введите название настроек метронома.');

        return ['name' => $name, 'settings' => MetronomeSettings::values($input['settings']),
            'id' => isset($input['id']) ? strtolower($input['id']) : null,
            'expected_revision' => $input['expected_revision'] ?? null];
    }

    public function store(Request $request)
    {
        $input = $this->input($request, false);
        $row = DB::transaction(function () use ($input) {
            $query = DB::table('practice_metronome_presets')->where('id', $input['id']);
            $row = (clone $query)->lockForUpdate()->first();
            if (! $row) {
                // A concurrent retry may insert the same UUID after the first lookup.
                DB::table('practice_metronome_presets')->insertOrIgnore([
                    'id' => $input['id'], 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(),
                    'name' => $input['name'], 'settings' => json_encode($input['settings'], JSON_THROW_ON_ERROR),
                    'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = (clone $query)->lockForUpdate()->first();
            }
            abort_unless($row && $row->workspace_id === $this->workspace->id()
                && (int) $row->user_id === $this->workspace->userId() && $row->archived_at === null
                && $row->name === $input['name']
                && MetronomeSettings::values(json_decode($row->settings, true)) == $input['settings'],
                409, 'Идентификатор сохранения уже использован. Обновите список настроек.');

            return $row;
        });

        return response()->json(['data' => $this->data($row)], 201);
    }

    public function update(Request $request, string $id)
    {
        $input = $this->input($request, true);
        $row = DB::transaction(function () use ($id, $input) {
            $row = $this->owned()->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $input['expected_revision'], 409, 'Настройки изменены в другом окне. Обновите список.');
            $this->owned()->where('id', $id)->update(['name' => $input['name'],
                'settings' => json_encode($input['settings'], JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1,
                'updated_at' => now()]);

            return $this->owned()->where('id', $id)->first();
        });

        return response()->json(['data' => $this->data($row)]);
    }

    public function destroy(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($id, $input) {
            $row = $this->owned()->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $input['expected_revision'], 409, 'Настройки изменены в другом окне. Обновите список.');
            $this->owned()->where('id', $id)->update(['archived_at' => now(), 'revision' => $row->revision + 1, 'updated_at' => now()]);
        });

        return response()->noContent();
    }
}
