<?php

namespace App\Modules\Practices\Actions;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\PublicApi\AsanaReader;
use App\Modules\Catalog\PublicApi\CatalogUsage;
use App\Modules\Catalog\PublicApi\IllustrationStyles;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\NativeImages;
use App\Modules\Practices\Services\ExecutionParameters;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PracticeEditor
{
    private array $assetCache = [];

    private ?string $workspaceId = null;

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AsanaReader $asanas,
        private readonly AssetStore $assets,
        private readonly Entitlements $entitlements,
    ) {}

    public function listing(array $filters, bool $rows = false): array
    {
        $query = $this->owned($rows ? 'practice_row_templates' : 'practice_documents');
        if (! $rows) {
            $query->where('kind', $filters['kind'] ?? 'practice');
        } else {
            $query->where('kind', $filters['kind'] ?? 'row');
        }
        if (! $rows && ! empty($filters['folder_id'])) {
            $folder = DB::table('practice_personal_folders')->where('workspace_id', $this->workspace())->where('user_id', $this->context->userId())->where('id', $filters['folder_id'])->first();
            abort_unless($folder, 404);
            $query->whereIn('id', DB::table('practice_document_folders')->where('folder_id', $folder->id)->select('document_id'));
        }
        if (! $rows && ($filters['include_cycle_documents'] ?? true) === false && empty($filters['q'])) {
            $query->whereNotIn('id', DB::table('practice_mesocycle_weeks')->select('document_id'));
        }
        $archived = ($filters['archive'] ?? '') === 'archived' || ($filters['archived'] ?? false);
        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_replace('ё', 'е', mb_strtolower($search)));
            $query->where(function (Builder $query) use ($search) {
                $query->whereRaw("replace(lower(title), 'ё', 'е') LIKE ?", ['%'.$search.'%'])
                    ->orWhereRaw("replace(lower(coalesce(theme, '')), 'ё', 'е') LIKE ?", ['%'.$search.'%']);
            });
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($filters['per_page'] ?? 18);
        $documentIds = array_map(fn ($record) => $record->id, $page->items());
        if ($rows) {
            $cellGroups = DB::table('practice_row_template_cells')->whereIn('row_template_id', $documentIds)
                ->orderBy('position')->orderBy('id')->get()->groupBy('row_template_id');
            $blockCounts = $rowCounts = collect();
        } else {
            $blockCounts = DB::table('practice_blocks')->whereIn('document_id', $documentIds)
                ->select('document_id')->selectRaw('count(*) as count')->groupBy('document_id')->pluck('count', 'document_id');
            $rowCounts = DB::table('practice_rows')->join('practice_blocks', 'practice_blocks.id', '=', 'practice_rows.block_id')
                ->whereIn('practice_blocks.document_id', $documentIds)->select('practice_blocks.document_id')
                ->selectRaw('count(*) as count')->groupBy('practice_blocks.document_id')->pluck('count', 'document_id');
            $cellGroups = DB::table('practice_cells')->join('practice_rows', 'practice_rows.id', '=', 'practice_cells.row_id')
                ->join('practice_blocks', 'practice_blocks.id', '=', 'practice_rows.block_id')
                ->whereIn('practice_blocks.document_id', $documentIds)
                ->orderBy('practice_blocks.position')->orderBy('practice_rows.position')->orderBy('practice_cells.position')
                ->select(array_map(fn ($field) => 'practice_cells.'.$field, ExecutionParameters::FIELDS))
                ->addSelect('practice_cells.id', 'practice_cells.card_snapshot_id', 'practice_cells.custom_name', 'practice_cells.group_key', 'practice_cells.arrow_direction', 'practice_cells.note', 'practice_blocks.document_id')->get()->groupBy('document_id');
        }
        $previewIds = $cellGroups->flatMap(fn ($cells) => $cells->pluck('card_snapshot_id')->filter()->unique()->take(4))->unique()->values()->all();
        $snapshots = $this->snapshots($previewIds);
        $relations = $rows ? DB::table('practice_transition_relations as relations')
            ->join('practice_row_templates as related', 'related.id', '=', 'relations.related_transition_id')
            ->whereIn('relations.transition_id', $documentIds)->where('related.workspace_id', $this->workspace())
            ->orderBy('related.title')->get(['relations.transition_id', 'related.id', 'related.title', 'related.archived_at', 'relations.kind'])
            ->groupBy('transition_id') : collect();
        $cycleLinks = ! $rows ? DB::table('practice_mesocycle_weeks as slots')->join('practice_mesocycles as cycles', 'cycles.id', '=', 'slots.mesocycle_id')->where('cycles.workspace_id', $this->workspace())->whereIn('slots.document_id', $documentIds)->select('slots.document_id', 'cycles.id', 'cycles.title', 'slots.position')->get()->groupBy('document_id') : collect();
        $folderLinks = ! $rows ? DB::table('practice_document_folders as links')->join('practice_personal_folders as folders', 'folders.id', '=', 'links.folder_id')->where('folders.workspace_id', $this->workspace())->where('folders.user_id', $this->context->userId())->whereNull('folders.archived_at')->whereIn('links.document_id', $documentIds)->select('links.document_id', 'folders.id', 'folders.name')->get()->groupBy('document_id') : collect();
        $items = [];
        foreach ($page->items() as $record) {
            $item = (array) $record;
            $cells = $cellGroups[$record->id] ?? collect();
            if (! $rows) {
                $item['cycles'] = ($cycleLinks[$record->id] ?? collect())->values()->all();
                $item['folders'] = ($folderLinks[$record->id] ?? collect())->values()->all();
            }
            $item['duration_seconds'] = $cells->filter(fn ($cell) => $cell->card_snapshot_id || $cell->custom_name)->contains(fn ($cell) => ! $cell->duration_seconds) ? null : $cells->sum(fn ($cell) => (int) ($cell->duration_seconds ?? 0) * max(1, (int) ($cell->repetitions ?? 1)) * (($cell->side ?? null) === 'both' ? 2 : 1));
            $item['cell_count'] = $item['cells_count'] = $cells->count();
            $item['filled_cell_count'] = $cells->filter(fn ($cell) => $cell->card_snapshot_id !== null || $cell->custom_name !== null)->count();
            $item['custom_cell_count'] = $cells->whereNotNull('custom_name')->count();
            if (! $rows) {
                $item['block_count'] = $item['blocks_count'] = (int) ($blockCounts[$record->id] ?? 0);
                $item['row_count'] = (int) ($rowCounts[$record->id] ?? 0);
            }
            $item['preview_cards'] = $cells->pluck('card_snapshot_id')->filter()->unique()->take(4)
                ->map(fn ($id) => $snapshots[$id])->values()->all();
            $item['preview_cells'] = $cells->filter(fn ($cell) => $cell->card_snapshot_id !== null || $cell->custom_name !== null)
                ->take(4)->map(fn ($cell) => $this->hydrateCell($cell, $snapshots))->values()->all();
            if ($rows) {
                $item['relations'] = ($relations[$record->id] ?? collect())
                    ->map(fn ($related) => ['transition_id' => $related->id, 'title' => $related->title, 'kind' => $related->kind, 'archived_at' => $related->archived_at])->all();
            }
            $items[] = $item;
        }

        return ['data' => $items, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    public function document(string $id): array
    {
        return DB::transaction(fn () => $this->hydrateDocument($this->find('practice_documents', $id, true)));
    }

    public function rowTemplate(string $id): array
    {
        return DB::transaction(fn () => $this->hydrateRow($this->find('practice_row_templates', $id, true)));
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->entitlements->lock($this->workspace());
            if (! empty($input['template_id'])) {
                $source = $this->find('practice_documents', $input['template_id'], true);
                abort_unless($source->kind === 'template' && ! $source->archived_at, 422, 'Выберите доступный шаблон практики.');
                $tree = $this->hydrateDocument($source);
                $tree['title'] = $input['title'] ?? $source->title;
                $tree['theme'] = $input['theme'] ?? $source->theme;
                $tree['practice_date'] = $input['practice_date'] ?? null;
            } else {
                $preferred = app(IllustrationStyles::class)->preferred();
                $tree = [
                    'illustration_style' => $input['illustration_style'] ?? $preferred['style'],
                    'illustration_character' => $input['illustration_character'] ?? $preferred['character'],
                    'title' => $input['title'] ?? 'Новая практика',
                    'theme' => $input['theme'] ?? null,
                    'note' => $input['note'] ?? null,
                    'practice_date' => $input['practice_date'] ?? null,
                    'blocks' => $this->defaultBlocks(),
                ];
            }

            return $this->insertDocument($tree, $input['kind'] ?? 'practice');
        });
    }

    public function save(string $id, array $input): array
    {
        $id = strtolower($id);

        return DB::transaction(function () use ($id, $input) {
            $record = $this->find('practice_documents', $id, true);
            $this->assertRevision($record, $input['expected_revision']);
            $this->assertEditable($record);
            foreach (['fragment_kind', 'illustration_style', 'illustration_character'] as $field) {
                if (! array_key_exists($field, $input)) {
                    $input[$field] = $record->$field ?? null;
                }
            }
            $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks'], $id);
            $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks'], $id, 'arrow_direction');
            foreach (ExecutionParameters::FIELDS as $field) {
                $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks'], $id, $field);
            }
            $this->validateTree($input['blocks'], $id);
            DB::table('practice_documents')->where('id', $id)->update($this->metadata($input) + [
                'revision' => $record->revision + 1,
                'updated_by' => $this->context->userId(),
                'updated_at' => now(),
            ]);
            $this->writeTree($id, $input['blocks']);

            return $this->document($id);
        });
    }

    public function copyLocal(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->entitlements->lock($this->workspace());
            $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks']);
            $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks'], null, 'arrow_direction');

            foreach (ExecutionParameters::FIELDS as $field) {
                $input['blocks'] = $this->restoreMissingTreeGroups($input['blocks'], null, $field);
            }

            return $this->insertDocument($input, 'practice');
        });
    }

    public function duplicate(string $id, array $input, bool $asTemplate = false): array
    {
        return DB::transaction(function () use ($id, $input, $asTemplate) {
            $this->entitlements->lock($this->workspace());
            $record = $this->find('practice_documents', $id, true);
            if (isset($input['expected_revision'])) {
                $this->assertRevision($record, $input['expected_revision']);
            }
            $tree = $this->hydrateDocument($record);
            $tree['title'] = $input['title'] ?? mb_substr($record->title, 0, 150).' — копия';

            return $this->insertDocument($tree, $asTemplate ? 'template' : $record->kind);
        });
    }

    public function copyForCycle(string $id, string $title, bool $asTemplate, int $expectedRevision): array
    {
        return DB::transaction(function () use ($id, $title, $asTemplate, $expectedRevision) {
            $record = $this->find('practice_documents', $id, true);
            $this->assertRevision($record, $expectedRevision);
            $this->assertEditable($record);
            $tree = $this->hydrateDocument($record);
            $tree['title'] = $title;

            return $this->insertDocument($tree, $asTemplate ? 'template' : 'practice');
        });
    }

    public function archive(string $id, int $revision, bool $restore = false, bool $rowTemplate = false): array
    {
        return DB::transaction(function () use ($id, $revision, $restore, $rowTemplate) {
            if (! $rowTemplate) {
                $this->entitlements->lock($this->workspace());
            }
            $table = $rowTemplate ? 'practice_row_templates' : 'practice_documents';
            $record = $this->find($table, $id, true);
            $this->assertRevision($record, $revision);
            if ($restore && ! $rowTemplate && $record->archived_at) {
                $this->entitlements->assertCanCreate($this->workspace(), 'practices', $this->owned('practice_documents')->whereNull('archived_at')->count());
            }
            DB::table($table)->where('id', $id)->update([
                'archived_at' => $restore ? null : now(),
                'revision' => $record->revision + 1,
                'updated_by' => $this->context->userId(),
                'updated_at' => now(),
            ]);

            return $rowTemplate ? $this->rowTemplate($id) : $this->document($id);
        });
    }

    public function saveRowTemplate(?string $id, array $input): array
    {
        $id = $id ? strtolower($id) : null;

        return DB::transaction(function () use ($id, $input) {
            if ($id) {
                $record = $this->find('practice_row_templates', $id, true);
                $this->assertRevision($record, $input['expected_revision']);
                $this->assertEditable($record);
                $revision = $record->revision + 1;
                $input['cells'] = $this->restoreMissingRowGroups($input['cells'], $id);
                $input['cells'] = $this->restoreMissingRowGroups($input['cells'], $id, 'arrow_direction');
                foreach (ExecutionParameters::FIELDS as $field) {
                    $input['cells'] = $this->restoreMissingRowGroups($input['cells'], $id, $field);
                }
            } else {
                $id = (string) Str::uuid();
                $revision = 1;
                $input['cells'] = $this->restoreMissingRowGroups($input['cells']);
                $input['cells'] = $this->restoreMissingRowGroups($input['cells'], null, 'arrow_direction');
                foreach (ExecutionParameters::FIELDS as $field) {
                    $input['cells'] = $this->restoreMissingRowGroups($input['cells'], null, $field);
                }
                foreach ($input['cells'] as &$cell) {
                    $cell['id'] = (string) Str::uuid();
                }
                unset($cell);
            }
            $this->validateCells($input['cells']);
            $this->validateRowGroups($input['cells']);
            $this->claimIds(array_map(fn ($cell) => ['id' => $cell['id'], 'entity_type' => 'cell'], $input['cells']), $id, 'row_template');
            $values = [
                'title' => $input['title'], 'theme' => $input['theme'] ?? null, 'note' => $input['note'] ?? null,
                'revision' => $revision, 'updated_by' => $this->context->userId(), 'updated_at' => now(),
            ];
            $values['kind'] = $input['kind'] ?? ($record->kind ?? 'row');
            if ($values['kind'] === 'transition' && count($input['cells']) < 2) {
                throw ValidationException::withMessages(['cells' => 'Связка содержит минимум две ячейки.']);
            }
            if ($revision === 1) {
                DB::table('practice_row_templates')->insert($values + [
                    'id' => $id, 'workspace_id' => $this->workspace(), 'created_by' => $this->context->userId(), 'created_at' => now(),
                ]);
            } else {
                DB::table('practice_row_templates')->where('id', $id)->update($values);
            }
            DB::table('practice_row_template_cells')->where('row_template_id', $id)->delete();
            DB::table('practice_row_template_cells')->insert(array_map(fn ($cell, $position) => [
                'id' => $cell['id'], 'row_template_id' => $id, 'position' => $position,
            ] + $this->cellContent($cell), $input['cells'], array_keys($input['cells'])));
            if (array_key_exists('relations', $input)) {
                if ($values['kind'] !== 'transition' && $input['relations']) {
                    throw ValidationException::withMessages(['relations' => 'Варианты можно задать только для связки.']);
                }
                $relations = [];
                $previousRelations = DB::table('practice_transition_relations')->where('transition_id', $id)->get()
                    ->mapWithKeys(fn ($relation) => [$relation->related_transition_id.':'.$relation->kind => true])->all();
                foreach ($input['relations'] as $relation) {
                    $target = $this->find('practice_row_templates', strtolower($relation['transition_id']));
                    if ($target->id === $id || $target->kind !== 'transition' || ($target->archived_at && ! isset($previousRelations[$target->id.':'.$relation['kind']]))) {
                        throw ValidationException::withMessages(['relations' => 'Выберите другую активную связку своего пространства.']);
                    }
                    $relations[$target->id.':'.$relation['kind']] = ['transition_id' => $id, 'related_transition_id' => $target->id, 'kind' => $relation['kind']];
                }
                DB::table('practice_transition_relations')->where('transition_id', $id)->delete();
                if ($relations) {
                    DB::table('practice_transition_relations')->insert(array_values($relations));
                }
            }

            return $this->rowTemplate($id);
        });
    }

    public function saveFragment(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->entitlements->lock($this->workspace());
            $input['fragment_kind'] = $input['fragment_kind'] ?? 'sequence';

            return $this->insertDocument($input, 'fragment');
        });
    }

    public function importTree(array $input, string $kind): array
    {
        abort_unless(in_array($kind, ['practice', 'template', 'fragment'], true), 422);

        return DB::transaction(function () use ($input, $kind) {
            $this->entitlements->lock($this->workspace());

            return $this->insertDocument($input, $kind);
        });
    }

    public function readSnapshots(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        $snapshots = $this->snapshots($ids);
        abort_unless(count($snapshots) === count($ids), 404, 'Один из рисунков недоступен в этом пространстве.');

        return array_map(fn ($id) => $snapshots[$id], $ids);
    }

    public function resolveCards(array $asanaIds, array $variantIds = [], array $illustration = ['style' => 'original', 'character' => 'neutral']): array
    {
        return DB::transaction(function () use ($asanaIds, $variantIds, $illustration) {
            $asanas = $this->asanas->findMany($asanaIds);
            $styles = app(IllustrationStyles::class);
            $result = [];
            foreach ($asanaIds as $id) {
                $asana = $asanas[$id] ?? null;
                if (! $asana || ! empty($asana['archived_at'])) {
                    throw ValidationException::withMessages(['asana_ids' => 'Одна из асан недоступна или находится в архиве. Обновите библиотеку.']);
                }
                $imageAssetId = $asana['image_asset_id'];
                $variantId = $variantIds[$id] ?? null;
                $drawing = null;
                if (array_key_exists($id, $variantIds)) {
                    $resolved = $styles->resolve($id, $imageAssetId, $variantId);
                    $imageAssetId = $resolved['image_asset_id'];
                    $drawing = $resolved['drawing_source'];
                } elseif ($illustration['style'] !== 'original') {
                    $prepared = $styles->prepare($id, $imageAssetId, $illustration['style'], $illustration['character']);
                    $imageAssetId = $prepared['image_asset_id'];
                    $variantId = $prepared['variant_id'] ?? null;
                    $drawing = $prepared['drawing_source'] ?? null;
                }
                if ($drawing) {
                    app(NativeImages::class)->remember($imageAssetId, $drawing);
                }
                $image = $this->assets->get($imageAssetId);
                $snapshotId = (string) Str::uuid();
                DB::table('practice_card_snapshots')->insert([
                    'id' => $snapshotId, 'workspace_id' => $this->workspace(), 'source_asana_id' => $id,
                    'name' => $asana['name'], 'image_asset_id' => $imageAssetId, 'created_at' => now(),
                ]);
                $result[] = [
                    'id' => $snapshotId, 'name' => $asana['name'], 'image_url' => $image['image_url'], 'source_asana_id' => $id,
                    'illustration_variant_id' => $variantId, 'image_asset_id' => $imageAssetId,
                ];
            }

            return $result;
        });
    }

    public function refreshPreview(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $document = $this->find('practice_documents', $id, true);
            $this->assertEditable($document);

            return ['revision' => (int) $document->revision, 'changes' => $this->snapshotChanges($id)];
        });
    }

    public function refreshSnapshots(string $id, int $revision, array $sourceIds, array $expectedAsanaRevisions): array
    {
        return DB::transaction(function () use ($id, $revision, $sourceIds, $expectedAsanaRevisions) {
            $document = $this->find('practice_documents', $id, true);
            $this->assertRevision($document, $revision);
            $this->assertEditable($document);
            $changes = array_filter($this->snapshotChanges($id), fn ($change) => in_array($change['source_asana_id'], $sourceIds, true));
            $expected = array_column($expectedAsanaRevisions, 'revision', 'asana_id');
            foreach ($this->asanas->findMany($sourceIds, true) as $card) {
                if ($card['archived_at'] || ($expected[$card['id']] ?? null) !== (int) $card['revision']) {
                    throw new HttpResponseException(response()->json(['message' => 'Карточка в библиотеке изменилась после предпросмотра. Закройте окно и проверьте изменения ещё раз.'], 409));
                }
            }
            foreach ($changes as $change) {
                if (($expected[$change['source_asana_id']] ?? null) !== $change['source_revision']) {
                    throw new HttpResponseException(response()->json(['message' => 'Карточка в библиотеке изменилась после предпросмотра. Закройте окно и проверьте изменения ещё раз.'], 409));
                }
            }
            $rowIds = DB::table('practice_rows')->join('practice_blocks', 'practice_blocks.id', '=', 'practice_rows.block_id')->where('practice_blocks.document_id', $id)->pluck('practice_rows.id')->all();
            $replacement = [];
            foreach ($changes as $change) {
                $source = $change['source_asana_id'];
                if (! isset($replacement[$source])) {
                    $replacement[$source] = $this->resolveCards([$source])[0]['id'];
                }
                DB::table('practice_cells')->whereIn('row_id', $rowIds)->where('card_snapshot_id', $change['snapshot_id'])->update(['card_snapshot_id' => $replacement[$source]]);
            }
            if ($changes) {
                DB::table('practice_documents')->where('id', $id)->update(['revision' => $document->revision + 1, 'updated_by' => $this->context->userId(), 'updated_at' => now()]);
            }

            return $this->document($id);
        });
    }

    private function snapshotChanges(string $id): array
    {
        $snapshotIds = DB::table('practice_cells')->join('practice_rows', 'practice_rows.id', '=', 'practice_cells.row_id')->join('practice_blocks', 'practice_blocks.id', '=', 'practice_rows.block_id')
            ->where('practice_blocks.document_id', $id)->whereNotNull('practice_cells.card_snapshot_id')->pluck('practice_cells.card_snapshot_id')->unique()->all();
        $snapshots = $this->owned('practice_card_snapshots')->whereIn('id', $snapshotIds)->whereNotNull('source_asana_id')->get();
        $ids = $snapshots->pluck('source_asana_id')->unique()->all();
        $cards = $this->asanas->findMany($ids, true);
        $images = $this->assets->getMany($snapshots->pluck('image_asset_id')->unique()->all());
        $changes = [];
        foreach ($snapshots as $snapshot) {
            $card = $cards[$snapshot->source_asana_id] ?? null;
            if (! $card || $card['archived_at'] || ($snapshot->name === $card['name'] && $snapshot->image_asset_id === $card['image_asset_id'])) {
                continue;
            }
            $changes[] = [
                'snapshot_id' => $snapshot->id, 'source_asana_id' => $snapshot->source_asana_id,
                'source_revision' => (int) $card['revision'],
                'before_name' => $snapshot->name, 'before_image_url' => $images[$snapshot->image_asset_id]['image_url'],
                'after_name' => $card['name'], 'after_image_url' => $card['image_url'],
            ];
        }

        return $changes;
    }

    private function owned(string $table): Builder
    {
        return DB::table($table)->where('workspace_id', $this->workspace());
    }

    private function workspace(): string
    {
        return $this->workspaceId ??= $this->context->id();
    }

    private function find(string $table, string $id, bool $lock = false): object
    {
        $query = $this->owned($table)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        abort_unless($record, 404, 'Документ не найден.');

        return $record;
    }

    private function assertRevision(object $record, int $expected): void
    {
        if ((int) $record->revision !== $expected) {
            throw new HttpResponseException(response()->json([
                'message' => 'Этот документ изменён в другом окне. Сохраните свою копию или загрузите актуальную версию.',
                'current_revision' => (int) $record->revision,
            ], 409));
        }
    }

    private function assertEditable(object $record): void
    {
        if ($record->archived_at) {
            throw new HttpResponseException(response()->json([
                'message' => 'Документ находится в архиве. Восстановите его перед редактированием.',
                'current_revision' => (int) $record->revision,
            ], 409));
        }
    }

    private function metadata(array $input): array
    {
        return [
            'title' => $input['title'], 'theme' => $input['theme'] ?? null,
            'note' => $input['note'] ?? null, 'practice_date' => $input['practice_date'] ?? null,
            'fragment_kind' => $input['fragment_kind'] ?? null,
            'illustration_style' => $input['illustration_style'] ?? null,
            'illustration_character' => $input['illustration_character'] ?? null,
        ];
    }

    private function defaultBlocks(): array
    {
        $blocks = [];
        for ($index = 0; $index < config('sequence.default_blocks'); $index++) {
            $cells = [];
            for ($cell = 0; $cell < config('sequence.default_cells'); $cell++) {
                $cells[] = ['id' => (string) Str::uuid(), 'card_snapshot_id' => null, 'custom_name' => null, 'group_key' => null, 'note' => null];
            }
            $blocks[] = [
                'id' => (string) Str::uuid(), 'title' => 'Блок '.($index + 1), 'purpose' => null,
                'rows' => [['id' => (string) Str::uuid(), 'note' => null, 'cells' => $cells]],
            ];
        }

        return $blocks;
    }

    private function insertDocument(array $tree, string $kind): array
    {
        $this->entitlements->assertCanCreate($this->workspace(), 'practices', $this->owned('practice_documents')->whereNull('archived_at')->count());
        $id = (string) Str::uuid();
        // A template/local-conflict copy owns entirely new structural identities.
        foreach ($tree['blocks'] as &$block) {
            $block['id'] = (string) Str::uuid();
            foreach ($block['rows'] as &$row) {
                $row['id'] = (string) Str::uuid();
                foreach ($row['cells'] as &$cell) {
                    $cell['id'] = (string) Str::uuid();
                }
                unset($cell);
            }
            unset($row);
        }
        unset($block);
        $this->validateTree($tree['blocks'], $id);
        DB::table('practice_documents')->insert($this->metadata($tree) + [
            'id' => $id, 'workspace_id' => $this->workspace(), 'kind' => $kind, 'revision' => 1,
            'created_by' => $this->context->userId(), 'updated_by' => $this->context->userId(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->writeTree($id, $tree['blocks']);

        return $this->document($id);
    }

    private function validateTree(array $blocks, string $documentId): void
    {
        $entities = [];
        $cells = [];
        foreach ($blocks as $block) {
            $entities[] = ['id' => $block['id'], 'entity_type' => 'block'];
            foreach ($block['rows'] as $row) {
                $entities[] = ['id' => $row['id'], 'entity_type' => 'row'];
                $this->validateRowGroups($row['cells']);
                foreach ($row['cells'] as $cell) {
                    $entities[] = ['id' => $cell['id'], 'entity_type' => 'cell'];
                    $cells[] = $cell;
                }
            }
        }
        if (count($cells) > config('sequence.max_cells_per_document')) {
            throw ValidationException::withMessages(['blocks' => 'Слишком много ячеек в одном документе.']);
        }
        $this->validateCells($cells);
        $this->claimIds($entities, $documentId, 'document');
    }

    private function validateCells(array $cells): void
    {
        $ids = array_values(array_unique(array_filter(array_column($cells, 'card_snapshot_id'))));
        $count = $this->owned('practice_card_snapshots')->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['cells' => 'Одна из карточек недоступна в этом рабочем пространстве.']);
        }
        foreach ($cells as $cell) {
            ExecutionParameters::values($cell);
            $customName = $this->customName($cell);
            if ($cell['card_snapshot_id'] !== null && $customName !== null) {
                throw ValidationException::withMessages(['cells' => 'В ячейке можно сохранить изображение асаны или своё название, но не оба одновременно.']);
            }
            if ($cell['card_snapshot_id'] === null && $customName === null && ($cell['note'] ?? '') !== '') {
                throw ValidationException::withMessages(['cells' => 'Пустая ячейка не может содержать подпись. Сначала добавьте асану или своё название.']);
            }
        }
    }

    private function customName(array $cell): ?string
    {
        $name = $cell['custom_name'] ?? null;
        if ($name === null) {
            return null;
        }
        if (is_string($name)) {
            $name = trim($name);
        }
        if (! is_string($name) || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['cells' => 'Своё название должно быть строкой не длиннее 160 символов.']);
        }

        return $name === '' ? null : $name;
    }

    private function cellContent(array $cell): array
    {
        $customName = $this->customName($cell);

        return ExecutionParameters::values($cell) + [
            'card_snapshot_id' => $cell['card_snapshot_id'],
            'custom_name' => $customName,
            'group_key' => $this->groupKey($cell),
            'arrow_direction' => $this->arrowDirection($cell),
            'note' => $cell['card_snapshot_id'] !== null || $customName !== null ? ($cell['note'] ?? null) : null,
        ];
    }

    private function arrowDirection(array $cell): ?string
    {
        $direction = $cell['arrow_direction'] ?? null;
        if ($direction !== null && ! in_array($direction, ['cross', 'both', 'up', 'down', 'left', 'right'], true)) {
            throw ValidationException::withMessages(['cells.arrow_direction' => 'Выберите четырёхстороннюю или горизонтальную двустороннюю стрелку.']);
        }

        return $direction;
    }

    private function groupKey(array $cell): ?string
    {
        $key = $cell['group_key'] ?? null;
        if ($key === null) {
            return null;
        }
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['cells.group_key' => 'Некорректный идентификатор группы ячеек.']);
        }

        return strtolower($key);
    }

    private function validateRowGroups(array $cells): void
    {
        $positions = [];
        foreach (array_values($cells) as $position => $cell) {
            $key = $this->groupKey($cell);
            if ($key !== null) {
                $positions[$key][] = $position;
            }
        }
        foreach ($positions as $members) {
            if (count($members) < 2) {
                throw ValidationException::withMessages(['cells.group_key' => 'Группа должна содержать минимум две ячейки одной строки. Обновите документ или измените группировку.']);
            }
            if (count($members) !== $members[count($members) - 1] - $members[0] + 1) {
                throw ValidationException::withMessages(['cells.group_key' => 'Ячейки одной группы должны стоять подряд в одной строке. Обновите документ или измените группировку.']);
            }
        }
    }

    private function restoreMissingTreeGroups(array $blocks, ?string $documentId = null, string $field = 'group_key'): array
    {
        $missingIds = [];
        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if (! array_key_exists($field, $cell)) {
                        $missingIds[] = $cell['id'];
                    }
                }
            }
        }
        if (! $missingIds) {
            return $blocks;
        }
        $groups = $this->knownCellGroups($missingIds, $documentId, null, $field);
        foreach ($blocks as &$block) {
            foreach ($block['rows'] as &$row) {
                $row['cells'] = $this->restoreCellGroups($row['cells'], $groups, $field);
            }
            unset($row);
        }
        unset($block);

        return $blocks;
    }

    private function restoreMissingRowGroups(array $cells, ?string $rowTemplateId = null, string $field = 'group_key'): array
    {
        $missingIds = array_column(array_filter($cells, fn ($cell) => ! array_key_exists($field, $cell)), 'id');

        return $missingIds ? $this->restoreCellGroups($cells, $this->knownCellGroups($missingIds, null, $rowTemplateId, $field), $field) : $cells;
    }

    private function restoreCellGroups(array $cells, array $groups, string $field): array
    {
        foreach ($cells as &$cell) {
            // Missing means an older client; explicit null removes the annotation.
            if (! array_key_exists($field, $cell)) {
                $cell[$field] = $groups[strtolower($cell['id'])] ?? null;
            }
        }
        unset($cell);

        return $cells;
    }

    private function knownCellGroups(array $ids, ?string $documentId, ?string $rowTemplateId, string $field): array
    {
        $groups = [];
        if ($rowTemplateId === null) {
            $query = DB::table('practice_cells')
                ->join('practice_rows', 'practice_rows.id', '=', 'practice_cells.row_id')
                ->join('practice_blocks', 'practice_blocks.id', '=', 'practice_rows.block_id')
                ->join('practice_documents', 'practice_documents.id', '=', 'practice_blocks.document_id')
                ->where('practice_documents.workspace_id', $this->workspace())->whereIn('practice_cells.id', $ids);
            if ($documentId !== null) {
                $query->where('practice_documents.id', $documentId);
            }
            $groups = $query->pluck('practice_cells.'.$field, 'practice_cells.id')->all();
        }
        if ($documentId === null) {
            $query = DB::table('practice_row_template_cells')
                ->join('practice_row_templates', 'practice_row_templates.id', '=', 'practice_row_template_cells.row_template_id')
                ->where('practice_row_templates.workspace_id', $this->workspace())->whereIn('practice_row_template_cells.id', $ids);
            if ($rowTemplateId !== null) {
                $query->where('practice_row_templates.id', $rowTemplateId);
            }
            $groups += $query->pluck('practice_row_template_cells.'.$field, 'practice_row_template_cells.id')->all();
        }

        return $groups;
    }

    private function claimIds(array $entities, string $ownerId, string $ownerType): void
    {
        $ids = array_column($entities, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['blocks' => 'У каждого блока, строки и ячейки должен быть уникальный идентификатор.']);
        }
        $existing = DB::table('practice_entity_ids')->whereIn('id', $ids)->get()->keyBy('id');
        $new = [];
        foreach ($entities as $entity) {
            $record = $existing[$entity['id']] ?? null;
            if ($record && ($record->owner_id !== $ownerId || $record->owner_type !== $ownerType || $record->entity_type !== $entity['entity_type'] || $record->workspace_id !== $this->workspace())) {
                throw ValidationException::withMessages(['blocks' => 'Нельзя использовать идентификаторы из другого документа. Создайте независимую копию.']);
            }
            if (! $record) {
                $new[] = $entity + ['workspace_id' => $this->workspace(), 'owner_id' => $ownerId, 'owner_type' => $ownerType];
            }
        }
        foreach (array_chunk($new, 300) as $chunk) {
            DB::table('practice_entity_ids')->insertOrIgnore($chunk);
        }
        // Another document may have claimed a new UUID concurrently. Recheck
        // after the unique insert rather than exposing a database exception.
        if ($new) {
            $claimed = DB::table('practice_entity_ids')->whereIn('id', array_column($new, 'id'))->get()->keyBy('id');
            foreach ($new as $entity) {
                $record = $claimed[$entity['id']] ?? null;
                if (! $record || $record->owner_id !== $ownerId || $record->owner_type !== $ownerType || $record->entity_type !== $entity['entity_type'] || $record->workspace_id !== $this->workspace()) {
                    throw ValidationException::withMessages(['blocks' => 'Идентификатор уже принадлежит другому документу. Создайте независимую копию.']);
                }
            }
        }
    }

    private function writeTree(string $id, array $blocks): void
    {
        // Replacing the complete tree inside the locked transaction preserves all
        // supplied identities and supports moving rows/cells between parents.
        DB::table('practice_blocks')->where('document_id', $id)->delete();
        $blockRecords = $rowRecords = $cellRecords = [];
        foreach ($blocks as $blockPosition => $block) {
            $blockRecords[] = [
                'id' => $block['id'], 'document_id' => $id, 'title' => $block['title'],
                'purpose' => $block['purpose'] ?? null, 'position' => $blockPosition,
            ];
            foreach ($block['rows'] as $rowPosition => $row) {
                $rowRecords[] = ['id' => $row['id'], 'block_id' => $block['id'], 'note' => $row['note'] ?? null, 'position' => $rowPosition];
                foreach ($row['cells'] as $cellPosition => $cell) {
                    $cellRecords[] = [
                        'id' => $cell['id'], 'row_id' => $row['id'], 'position' => $cellPosition,
                    ] + $this->cellContent($cell);
                }
            }
        }
        DB::table('practice_blocks')->insert($blockRecords);
        foreach (array_chunk($rowRecords, 300) as $chunk) {
            DB::table('practice_rows')->insert($chunk);
        }
        foreach (array_chunk($cellRecords, 300) as $chunk) {
            DB::table('practice_cells')->insert($chunk);
        }
        $snapshotIds = array_values(array_unique(array_filter(array_column($cellRecords, 'card_snapshot_id'))));
        $sourceIds = $this->owned('practice_card_snapshots')->whereIn('id', $snapshotIds)->whereNotNull('source_asana_id')->pluck('source_asana_id')->unique()->all();
        if ($sourceIds) {
            app(CatalogUsage::class)->record($sourceIds);
        }
    }

    private function hydrateDocument(object $record): array
    {
        $blocks = DB::table('practice_blocks')->where('document_id', $record->id)->orderBy('position')->orderBy('id')->get();
        $rows = DB::table('practice_rows')->whereIn('block_id', $blocks->pluck('id'))->orderBy('position')->orderBy('id')->get();
        $cells = DB::table('practice_cells')->whereIn('row_id', $rows->pluck('id'))->orderBy('position')->orderBy('id')->get();
        $snapshots = $this->snapshots($cells->pluck('card_snapshot_id')->filter()->unique()->values()->all());
        $cellGroups = $cells->groupBy('row_id');
        $rowGroups = $rows->groupBy('block_id');
        $data = (array) $record;
        $data['revision'] = (int) $record->revision;
        $data['blocks'] = $blocks->map(fn ($block) => [
            'id' => $block->id, 'title' => $block->title, 'purpose' => $block->purpose,
            'rows' => ($rowGroups[$block->id] ?? collect())->map(fn ($row) => [
                'id' => $row->id, 'note' => $row->note,
                'cells' => ($cellGroups[$row->id] ?? collect())->map(fn ($cell) => $this->hydrateCell($cell, $snapshots))->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        return $data;
    }

    private function hydrateRow(object $record): array
    {
        $cells = DB::table('practice_row_template_cells')->where('row_template_id', $record->id)->orderBy('position')->orderBy('id')->get();
        $snapshots = $this->snapshots($cells->pluck('card_snapshot_id')->filter()->unique()->values()->all());
        $data = (array) $record;
        $data['revision'] = (int) $record->revision;
        $data['cells'] = $cells->map(fn ($cell) => $this->hydrateCell($cell, $snapshots))->values()->all();
        $data['relations'] = DB::table('practice_transition_relations as relations')->join('practice_row_templates as related', 'related.id', '=', 'relations.related_transition_id')
            ->where('relations.transition_id', $record->id)->where('related.workspace_id', $this->workspace())
            ->orderBy('related.title')->get(['related.id', 'related.title', 'related.archived_at', 'relations.kind'])
            ->map(fn ($related) => ['transition_id' => $related->id, 'title' => $related->title, 'kind' => $related->kind, 'archived_at' => $related->archived_at])->all();

        return $data;
    }

    private function hydrateCell(object $cell, array $snapshots): array
    {
        return ExecutionParameters::values($cell) + ['id' => $cell->id, 'card_snapshot_id' => $cell->card_snapshot_id, 'custom_name' => $cell->custom_name, 'group_key' => $cell->group_key, 'arrow_direction' => $cell->arrow_direction, 'note' => $cell->note, 'card' => $snapshots[$cell->card_snapshot_id ?? ''] ?? null];
    }

    private function snapshots(array $ids): array
    {
        $records = $this->owned('practice_card_snapshots')->whereIn('id', $ids)->get();
        $missingAssetIds = array_values(array_diff($records->pluck('image_asset_id')->unique()->all(), array_keys($this->assetCache)));
        if ($missingAssetIds) {
            $this->assetCache += $this->assets->getMany($missingAssetIds);
        }
        $result = [];
        foreach ($records as $snapshot) {
            $asset = $this->assetCache[$snapshot->image_asset_id];
            $result[$snapshot->id] = [
                'id' => $snapshot->id, 'name' => $snapshot->name,
                'image_url' => $asset['image_url'], 'source_asana_id' => $snapshot->source_asana_id,
            ];
        }

        return $result;
    }
}
