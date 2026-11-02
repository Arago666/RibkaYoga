<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\UserDirectory;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Personal annotations never write the source document or its catalogue cards. */
class PracticeReviews
{
    public function __construct(private WorkspaceContext $workspace, private DocumentSharing $sharing, private AssetStore $assets, private UserDirectory $users) {}

    private function owned(): Builder
    {
        return DB::table('practice_reviews')->where('practice_reviews.workspace_id', $this->workspace->id())
            ->where('practice_reviews.user_id', $this->workspace->userId());
    }

    private function accessibleSources(bool $onlyActive = true): Builder
    {
        $query = DB::table('practice_documents as documents')->where('documents.kind', 'practice')
            ->where(function (Builder $query) {
                $query->where('documents.workspace_id', $this->workspace->id())
                    ->orWhere(function (Builder $query) {
                        $query->whereNull('documents.archived_at')->whereExists(function (Builder $query) {
                            $query->selectRaw('1')->from('practice_document_shares as shares')
                                ->whereColumn('shares.document_id', 'documents.id')
                                ->where('shares.recipient_user_id', $this->workspace->userId())->whereNull('shares.revoked_at');
                        });
                    });
            });
        if ($onlyActive) {
            $query->whereNull('documents.archived_at');
        }

        return $query;
    }

    public function sources(array $filters): array
    {
        $query = $this->accessibleSources();
        if (($filters['scope'] ?? 'all') === 'mine') {
            $query->where('documents.workspace_id', $this->workspace->id());
        } elseif (($filters['scope'] ?? 'all') === 'shared') {
            $query->where('documents.workspace_id', '<>', $this->workspace->id());
        }
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_replace('ё', 'е', mb_strtolower($search)));
            $query->whereRaw("replace(lower(documents.title), 'ё', 'е') LIKE ?", ['%'.$search.'%']);
        }
        $page = $query->orderByDesc('documents.updated_at')->orderBy('documents.id')
            ->paginate($filters['per_page'] ?? 18, ['documents.id', 'documents.title', 'documents.revision', 'documents.workspace_id', 'documents.created_by', 'documents.updated_at']);
        $owners = $this->users->findMany(collect($page->items())->pluck('created_by')->all());
        $data = array_map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'revision' => (int) $row->revision,
            'scope' => $row->workspace_id === $this->workspace->id() ? 'mine' : 'shared',
            'owner' => $owners[$row->created_by]['name'] ?? 'Преподаватель', 'updated_at' => $row->updated_at], $page->items());

        return ['data' => $data, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    public function listing(array $filters): array
    {
        $query = $this->owned()->join('practice_documents as source', 'source.id', '=', 'practice_reviews.source_document_id')
            ->whereIn('practice_reviews.source_document_id', $this->accessibleSources(false)->select('documents.id'));
        ($filters['archive'] ?? 'active') === 'archived'
            ? $query->whereNotNull('practice_reviews.archived_at')
            : $query->whereNull('practice_reviews.archived_at');
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_replace('ё', 'е', mb_strtolower($search)));
            $query->whereRaw("replace(lower(practice_reviews.title), 'ё', 'е') LIKE ?", ['%'.$search.'%']);
        }
        $page = $query->orderByDesc('practice_reviews.updated_at')->orderBy('practice_reviews.id')->paginate($filters['per_page'] ?? 18,
            ['practice_reviews.*', 'source.revision as current_source_revision']);
        $data = array_map(function ($row) {
            $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $annotations = json_decode($row->annotations, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $row->id, 'title' => $row->title, 'source_document_id' => $row->source_document_id,
                'source_title' => $snapshot['title'], 'source_revision' => (int) $row->source_revision,
                'current_source_revision' => (int) $row->current_source_revision, 'revision' => (int) $row->revision,
                'comments_count' => count($annotations['comments']), 'groups_count' => count($annotations['groups']),
                'arrows_count' => count($annotations['arrows']), 'created_at' => $row->created_at,
                'updated_at' => $row->updated_at, 'archived_at' => $row->archived_at];
        }, $page->items());

        return ['data' => $data, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    private function record(string $id, bool $lock = false): array
    {
        $query = $this->owned()->where('practice_reviews.id', strtolower($id));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        $access = $this->sharing->access($row->source_document_id, $lock);

        return [$row, $access];
    }

    public function read(string $id): array
    {
        [$row, $access] = $this->record($id);
        $document = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        foreach ($document['blocks'] as &$block) {
            foreach ($block['rows'] as &$sourceRow) {
                foreach ($sourceRow['cells'] as &$cell) {
                    if ($cell['card']) {
                        $cell['card']['image_url'] = '/api/practice-reviews/'.$row->id.'/media/'.$cell['card']['image_asset_id'];
                    }
                }
                unset($cell);
            }
            unset($sourceRow);
        }
        unset($block);

        return ['id' => $row->id, 'title' => $row->title, 'source_document_id' => $row->source_document_id,
            'source_revision' => (int) $row->source_revision, 'current_source_revision' => (int) $access['document']->revision,
            'source_changed' => (int) $row->source_revision !== (int) $access['document']->revision,
            'revision' => (int) $row->revision, 'document' => $document,
            'annotations' => json_decode($row->annotations, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'archived_at' => $row->archived_at];
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $id = strtolower($input['id']);
            $sourceId = strtolower($input['document_id']);
            $existing = DB::table('practice_reviews')->where('id', $id)->lockForUpdate()->first();
            if ($existing) {
                $this->assertSameCreation($existing, $sourceId, (int) $input['expected_document_revision']);
                abort_if(isset($input['title']) && trim($input['title']) !== '' && trim($input['title']) !== $existing->title,
                    409, 'Разбор с этим идентификатором уже имеет другое название.');

                return $this->read($id);
            }
            $source = $this->sharing->access($sourceId, true)['document'];
            abort_unless($source->kind === 'practice' && $source->archived_at === null, 422, 'Выберите действующую практику.');
            abort_unless((int) $source->revision === (int) $input['expected_document_revision'], 409, 'Практика изменилась. Обновите список и откройте её снова.');
            $document = $this->sharing->viewer($sourceId)['document'];
            $snapshotIds = collect($document['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => collect($row['cells'])->pluck('card_snapshot_id')))->filter()->unique()->values()->all();
            $snapshots = DB::table('practice_card_snapshots')->where('workspace_id', $source->workspace_id)->whereIn('id', $snapshotIds)->get()->keyBy('id');
            abort_unless(count($snapshotIds) === $snapshots->count(), 422, 'Не все изображения практики доступны.');
            foreach ($document['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        if ($cell['card']) {
                            $cell['card']['image_asset_id'] = $snapshots[$cell['card_snapshot_id']]->image_asset_id;
                            unset($cell['card']['image_url']);
                        }
                    }
                    unset($cell);
                }
                unset($row);
            }
            unset($block);
            $title = trim($input['title'] ?? '') ?: mb_substr($document['title'], 0, 160);
            DB::table('practice_reviews')->insertOrIgnore(['id' => $id, 'workspace_id' => $this->workspace->id(),
                'user_id' => $this->workspace->userId(), 'source_document_id' => $sourceId, 'source_revision' => $source->revision,
                'title' => $title, 'snapshot' => json_encode($document, JSON_THROW_ON_ERROR),
                'annotations' => json_encode(ReviewAnnotations::empty(), JSON_THROW_ON_ERROR), 'revision' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            $created = DB::table('practice_reviews')->where('id', $id)->lockForUpdate()->first();
            $this->assertSameCreation($created, $sourceId, (int) $input['expected_document_revision']);
            foreach ($snapshots->pluck('image_asset_id')->unique() as $assetId) {
                DB::table('practice_review_assets')->insertOrIgnore(['review_id' => $id, 'image_asset_id' => $assetId]);
            }

            return $this->read($id);
        });
    }

    private function assertSameCreation(?object $row, string $sourceId, int $revision): void
    {
        abort_unless($row && $row->workspace_id === $this->workspace->id() && (int) $row->user_id === $this->workspace->userId()
            && $row->source_document_id === $sourceId && (int) $row->source_revision === $revision && $row->archived_at === null,
            409, 'Этот идентификатор разбора уже использован. Откройте список разборов.');
    }

    public function save(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            [$row] = $this->record($id, true);
            abort_unless($row->archived_at === null, 409, 'Разбор находится в архиве.');
            abort_unless((int) $row->revision === (int) $input['expected_revision'], 409, 'Разбор изменён в другом окне. Сохраните свой текст отдельно и обновите страницу.');
            $annotations = ReviewAnnotations::values($input['annotations'], json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR));
            $title = trim($input['title']);
            abort_unless($title !== '', 422, 'Введите название разбора.');
            $this->owned()->where('practice_reviews.id', $row->id)->update(['title' => $title,
                'annotations' => json_encode($annotations, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1, 'updated_at' => now()]);

            return $this->read($row->id);
        });
    }

    public function copy(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            [$source] = $this->record($id, true);
            $newId = strtolower($input['id']);
            abort_if($newId === $source->id, 422, 'Копия должна иметь собственный идентификатор.');
            $document = json_decode($source->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $annotations = ReviewAnnotations::values($input['annotations'] ?? json_decode($source->annotations, true, 512, JSON_THROW_ON_ERROR), $document);
            $existing = DB::table('practice_reviews')->where('id', $newId)->lockForUpdate()->first();
            if ($existing) {
                $this->assertSameCreation($existing, $source->source_document_id, (int) $source->source_revision);
                abort_unless($existing->snapshot === $source->snapshot, 409, 'Этот идентификатор копии уже использован.');
                $this->assertSameCopy($existing, $input, $annotations);

                return $this->read($newId);
            }
            abort_unless((int) $source->revision === (int) $input['expected_revision'], 409, 'Разбор изменён в другом окне. Обновите его ревизию перед созданием копии.');
            $title = trim($input['title'] ?? '') ?: mb_substr($source->title.' — копия', 0, 160);
            DB::table('practice_reviews')->insertOrIgnore(['id' => $newId, 'workspace_id' => $this->workspace->id(),
                'user_id' => $this->workspace->userId(), 'source_document_id' => $source->source_document_id,
                'source_revision' => $source->source_revision, 'title' => $title, 'snapshot' => $source->snapshot,
                'annotations' => json_encode($annotations, JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $created = DB::table('practice_reviews')->where('id', $newId)->lockForUpdate()->first();
            $this->assertSameCreation($created, $source->source_document_id, (int) $source->source_revision);
            abort_unless($created->snapshot === $source->snapshot, 409, 'Этот идентификатор копии уже использован.');
            $this->assertSameCopy($created, $input, $annotations);
            foreach (DB::table('practice_review_assets')->where('review_id', $source->id)->pluck('image_asset_id') as $assetId) {
                DB::table('practice_review_assets')->insertOrIgnore(['review_id' => $newId, 'image_asset_id' => $assetId]);
            }

            return $this->read($newId);
        });
    }

    private function assertSameCopy(object $row, array $input, array $annotations): void
    {
        abort_if(isset($input['title']) && trim($input['title']) !== '' && trim($input['title']) !== $row->title,
            409, 'Копия с этим идентификатором уже имеет другое название.');
        abort_if(array_key_exists('annotations', $input) && json_decode($row->annotations, true, 512, JSON_THROW_ON_ERROR) != $annotations,
            409, 'Копия с этим идентификатором уже содержит другие пометки.');
    }

    public function archive(string $id, int $revision, bool $restore = false): array
    {
        return DB::transaction(function () use ($id, $revision, $restore) {
            [$row] = $this->record($id, true);
            abort_unless((int) $row->revision === $revision, 409, 'Разбор изменён в другом окне. Обновите список.');
            $this->owned()->where('practice_reviews.id', $row->id)->update(['archived_at' => $restore ? null : now(),
                'revision' => $row->revision + 1, 'updated_at' => now()]);

            return $this->read($row->id);
        });
    }

    public function media(string $id, string $assetId): array
    {
        return $this->assets->forReview($assetId, $id);
    }

    /** @return array<string, string> URLs or embedded images keyed by immutable card snapshot UUID. */
    public function images(string $id, bool $embedded = false): array
    {
        $review = $this->read($id);
        $images = [];
        $cache = [];
        $bytes = 0;
        foreach ($review['document']['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if (! $cell['card']) {
                        continue;
                    }
                    $assetId = $cell['card']['image_asset_id'];
                    if (! isset($cache[$assetId])) {
                        if ($embedded) {
                            $file = $this->media($review['id'], $assetId);
                            $bytes += (int) filesize($file['path']);
                            abort_if($bytes > 48 * 1024 * 1024, 422, 'Изображения разбора слишком велики для PDF.');
                            $contents = file_get_contents($file['path']);
                            abort_unless(is_string($contents) && $contents !== '', 422, 'Не удалось прочитать изображение разбора.');
                            $cache[$assetId] = 'data:'.$file['mime'].';base64,'.base64_encode($contents);
                        } else {
                            $cache[$assetId] = $cell['card']['image_url'];
                        }
                    }
                    $images[$cell['card_snapshot_id']] = $cache[$assetId];
                }
            }
        }

        return $images;
    }
}
