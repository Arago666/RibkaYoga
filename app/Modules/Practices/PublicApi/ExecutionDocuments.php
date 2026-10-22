<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\PoseFacts;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\Services\DocumentSharing;
use Illuminate\Support\Facades\DB;

class ExecutionDocuments
{
    public function __construct(private WorkspaceContext $workspace, private PracticeEditor $editor, private DocumentSharing $sharing) {}

    public function read(string $id): array
    {
        $access = $this->sharing->access($id);

        return $access['owner'] ? $this->editor->document($id) : $this->sharing->viewer($id)['document'];
    }

    public function plan(string $id): array
    {
        $access = $this->sharing->access($id);
        abort_if($access['document']->archived_at !== null, 422, 'Сначала восстановите практику.');
        $document = $this->read($id);
        $ids = collect($document['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => collect($row['cells'])->pluck('card_snapshot_id')))->filter()->unique()->all();
        $snapshots = DB::table('practice_card_snapshots')->where('workspace_id', $access['document']->workspace_id)->whereIn('id', $ids)->get()->keyBy('id');
        $facts = [];
        if ($access['owner'] && class_exists(PoseFacts::class)) {
            $pairs = $snapshots->filter(fn ($snapshot) => $snapshot->source_asana_id && $snapshot->classification_snapshot === null)->map(fn ($snapshot) => ['asana_id' => $snapshot->source_asana_id, 'image_asset_id' => $snapshot->image_asset_id])->values()->all();
            $facts = app(PoseFacts::class)->forImages($pairs);
        }

        return ['document_id' => $id, 'revision' => $document['revision'], 'title' => $document['title'], 'steps' => $this->steps($document, $snapshots->all(), $facts)];
    }

    public function steps(array $document, array $snapshots = [], array $facts = []): array
    {
        $steps = [];
        foreach ($document['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if (! $cell['card'] && ! trim($cell['custom_name'] ?? '')) {
                        continue;
                    }
                    $sides = match ($cell['side'] ?? null) {
                        'both' => ($cell['side_order'] ?? 'left_first') === 'right_first' ? ['right', 'left'] : ['left', 'right'],
                        'left' => ['left'], 'right' => ['right'], default => [null],
                    };
                    $snapshot = $snapshots[$cell['card_snapshot_id'] ?? ''] ?? null;
                    $fact = $snapshot ? (app(SnapshotClassifications::class)->fromStored($snapshot->classification_snapshot ?? null) ?? ($facts[$snapshot->image_asset_id] ?? null)) : null;
                    $repetitions = (int) ($cell['repetitions'] ?? 1);
                    abort_if($repetitions < 1 || $repetitions > 1000 || count($steps) + count($sides) * $repetitions > 10000, 422, 'Слишком много повторений для одного занятия.');
                    foreach ($sides as $side) {
                        for ($repeat = 1; $repeat <= $repetitions; $repeat++) {
                            $steps[] = ['id' => $cell['id'].':'.($side ?? 'none').':'.$repeat, 'cell_id' => $cell['id'], 'name' => $cell['card']['name'] ?? $cell['custom_name'], 'image_url' => $cell['card']['image_url'] ?? null, 'note' => $cell['note'] ?? null, 'side' => $side, 'repeat' => $repeat, 'advance_mode' => $cell['advance_mode'] ?? 'manual', 'duration_seconds' => $cell['duration_seconds'] ?? null, 'breath_count' => $cell['breath_count'] ?? null, 'anatomy' => $fact['anatomy'] ?? [], 'classification_revision' => $fact['revision'] ?? null];
                        }
                    }
                }
            }
        }
        abort_if(count($steps) > 10000, 422, 'Слишком много повторений для одного занятия.');

        return $steps;
    }
}
