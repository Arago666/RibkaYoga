<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\IllustrationStyles;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\PublicApi\NativeImages;
use App\Modules\Practices\PublicApi\SnapshotClassifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PracticeIllustrations
{
    public function __construct(private WorkspaceContext $workspace, private PracticeEditor $editor, private IllustrationStyles $styles, private AssetStore $assets, private NativeImages $native) {}

    public function preview(string $documentId, int $revision, string $style, string $character): array
    {
        $document = $this->editor->document($documentId);
        abort_unless((int) $document['revision'] === $revision, 409, 'Практика изменилась. Сохраните и обновите её перед предпросмотром.');
        abort_if(! empty($document['archived_at']), 422, 'Сначала восстановите практику.');
        $ids = collect($document['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => collect($row['cells'])->pluck('card_snapshot_id')))->filter()->unique()->values()->all();
        $snapshots = DB::table('practice_card_snapshots')->where('workspace_id', $this->workspace->id())->whereIn('id', $ids)->get();
        $changes = [];
        $skipped = [];
        foreach ($snapshots as $snapshot) {
            if (! $snapshot->source_asana_id) {
                $skipped[] = ['snapshot_id' => $snapshot->id, 'name' => $snapshot->name, 'reason' => 'Собственный рисунок без проверенного соответствия каталогу.'];

                continue;
            }
            $prepared = $this->styles->prepareForSnapshot($snapshot->source_asana_id, $snapshot->image_asset_id, $style, $character);
            if ($prepared['image_asset_id'] === $snapshot->image_asset_id) {
                $skipped[] = ['snapshot_id' => $snapshot->id, 'name' => $snapshot->name, 'reason' => $prepared['reason'] ?? 'Это представление уже используется.'];

                continue;
            }
            $changes[] = [
                'snapshot_id' => $snapshot->id, 'name' => $snapshot->name,
                'before_image_url' => $this->assets->get($snapshot->image_asset_id)['image_url'],
                'after_image_url' => $prepared['image_url'],
                'before' => (array) $snapshot, 'image_asset_id' => $prepared['image_asset_id'],
                'drawing_source' => $prepared['drawing_source'] ?? null,
            ];
        }
        $token = Str::random(40);
        Cache::put($this->key($token), ['document_id' => $documentId, 'revision' => $revision, 'style' => $style, 'character' => $character, 'changes' => $changes], now()->addMinutes(30));

        return ['token' => $token, 'revision' => $revision, 'changes' => array_map(fn ($change) => array_intersect_key($change, array_flip(['snapshot_id', 'name', 'before_image_url', 'after_image_url'])), $changes), 'skipped' => $skipped];
    }

    public function apply(string $documentId, int $revision, string $token, array $selected): array
    {
        $plan = Cache::get($this->key($token));
        abort_unless(is_array($plan) && $plan['document_id'] === $documentId && (int) $plan['revision'] === $revision, 409, 'Предпросмотр истёк или относится к другой версии. Подготовьте его снова.');
        abort_unless(count($selected) > 0, 422, 'Выберите хотя бы одно изображение для замены.');
        $allowed = array_column($plan['changes'], null, 'snapshot_id');
        abort_unless(count(array_diff($selected, array_keys($allowed))) === 0, 422, 'В списке есть неподготовленное изображение.');
        if (Cache::has($this->key($token).':applied')) {
            return $this->editor->document($documentId);
        }
        $saved = DB::transaction(function () use ($plan, $selected, $allowed, $revision, $documentId) {
            $document = $this->editor->document($documentId);
            abort_unless((int) $document['revision'] === $revision, 409, 'Практика изменилась после предпросмотра. Ничего не заменено.');
            $replacements = [];
            foreach ($selected as $oldId) {
                $change = $allowed[$oldId];
                $old = DB::table('practice_card_snapshots')->where('workspace_id', $this->workspace->id())->where('id', $oldId)->first();
                abort_unless($old && (array) $old === $change['before'], 409, 'Исходный снимок изменился. Подготовьте предпросмотр заново.');
                $this->assets->get($change['image_asset_id']);
                if ($change['drawing_source']) {
                    $this->native->remember($change['image_asset_id'], $change['drawing_source']);
                }
                $newId = (string) Str::uuid();
                $classifications = app(SnapshotClassifications::class);
                $pin = $classifications->fromStored($old->classification_snapshot ?? null) ?? $classifications->capture($old->source_asana_id, $change['image_asset_id']);
                DB::table('practice_card_snapshots')->insert(['id' => $newId, 'workspace_id' => $old->workspace_id, 'source_asana_id' => $old->source_asana_id, 'name' => $old->name, 'image_asset_id' => $change['image_asset_id'], 'classification_snapshot' => $classifications->encode($pin), 'created_at' => now()]);
                $replacements[$oldId] = $newId;
            }
            foreach ($document['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        if (isset($replacements[$cell['card_snapshot_id'] ?? ''])) {
                            $cell['card_snapshot_id'] = $replacements[$cell['card_snapshot_id']];
                        }
                    }
                    unset($cell);
                }
                unset($row);
            }
            unset($block);
            $document['expected_revision'] = $revision;
            $document['illustration_style'] = $plan['style'];
            $document['illustration_character'] = $plan['character'];

            return $this->editor->save($documentId, $document);
        });
        Cache::put($this->key($token).':applied', true, now()->addMinutes(30));

        return $saved;
    }

    private function key(string $token): string
    {
        return 'illustration-plan:'.$this->workspace->id().':'.$this->workspace->userId().':'.$token;
    }
}
