<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\UserDirectory;
use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentSharing
{
    public function __construct(private WorkspaceContext $workspace, private UserDirectory $users) {}

    public function owned(string $documentId, bool $lock = false): object
    {
        $query = DB::table('practice_documents')->where('id', strtolower($documentId))->where('workspace_id', $this->workspace->id());
        $document = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($document, 404);

        return $document;
    }

    /** Owners use their workspace; recipients receive access to this document alone. */
    public function access(string $documentId, bool $lock = false): array
    {
        $viewer = $this->workspace->userId();
        $query = DB::table('practice_documents')->where('id', strtolower($documentId));
        $document = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($document, 404);
        $owner = $document->workspace_id === $this->workspace->id();
        $share = null;
        if (! $owner) {
            abort_if($document->archived_at !== null, 404);
            $query = DB::table('practice_document_shares')->where('document_id', $document->id)
                ->where('recipient_user_id', $viewer)->whereNull('revoked_at');
            $share = ($lock ? $query->lockForUpdate() : $query)->first();
            abort_unless($share, 404);
        }

        return ['document' => $document, 'owner' => $owner, 'share' => $share, 'viewer_id' => $viewer];
    }

    public function permissions(string $documentId): array
    {
        $document = $this->owned($documentId);

        return ['document_revision' => (int) $document->revision, 'data' => $this->shares($document->id)];
    }

    public function grant(string $documentId, array $input): array
    {
        return DB::transaction(function () use ($documentId, $input) {
            $document = $this->owned($documentId, true);
            abort_if($document->archived_at !== null, 422, 'Сначала восстановите практику из архива.');
            $this->documentRevision($document, $input['expected_document_revision']);
            $recipient = $this->users->findByLogin($input['login']);
            if (! $recipient) {
                throw ValidationException::withMessages(['login' => 'Аккаунт с таким логином не найден.']);
            }
            if ($recipient['id'] === $this->workspace->userId()) {
                throw ValidationException::withMessages(['login' => 'У вас уже есть доступ к своей практике. Укажите другого преподавателя.']);
            }
            $existing = DB::table('practice_document_shares')->where('document_id', $document->id)
                ->where('recipient_user_id', $recipient['id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->revision === (int) ($input['expected_revision'] ?? 0), 409, 'Доступ изменён в другом окне. Обновите список преподавателей.');
            } else {
                abort_if(isset($input['expected_revision']), 409, 'Доступ изменён в другом окне. Обновите список преподавателей.');
            }
            if ((! $existing || $existing->revoked_at) && DB::table('practice_document_shares')->where('document_id', $document->id)->whereNull('revoked_at')->count() >= 100) {
                throw ValidationException::withMessages(['login' => 'У этой практики уже 100 действующих доступов. Сначала отзовите ненужный.']);
            }
            $values = [
                'allow_copy' => $input['allow_copy'] ?? ($existing->allow_copy ?? false),
                'permission' => $input['permission'], 'revision' => $existing ? $existing->revision + 1 : 1,
                'revoked_at' => null, 'updated_by' => $this->workspace->userId(), 'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('practice_document_shares')->where('id', $existing->id)->update($values);
            } else {
                DB::table('practice_document_shares')->insert($values + [
                    'id' => (string) Str::uuid(), 'document_id' => $document->id, 'recipient_user_id' => $recipient['id'],
                    'created_by' => $this->workspace->userId(), 'created_at' => now(),
                ]);
            }

            return $this->permissions($document->id);
        });
    }

    public function revoke(string $documentId, string $shareId, array $input): array
    {
        return DB::transaction(function () use ($documentId, $shareId, $input) {
            $document = $this->owned($documentId, true);
            $this->documentRevision($document, $input['expected_document_revision']);
            $share = DB::table('practice_document_shares')->where('document_id', $document->id)->where('id', $shareId)->lockForUpdate()->first();
            abort_unless($share, 404);
            abort_unless((int) $share->revision === (int) $input['expected_revision'], 409, 'Доступ изменён в другом окне. Обновите список преподавателей.');
            DB::table('practice_document_shares')->where('id', $shareId)->update([
                'revoked_at' => now(), 'revision' => $share->revision + 1, 'updated_by' => $this->workspace->userId(), 'updated_at' => now(),
            ]);

            return $this->permissions($document->id);
        });
    }

    public function listing(array $filters = []): array
    {
        $records = DB::table('practice_document_shares as shares')->join('practice_documents as documents', 'documents.id', '=', 'shares.document_id')
            ->where('shares.recipient_user_id', $this->workspace->userId())->whereNull('shares.revoked_at')->whereNull('documents.archived_at')
            ->orderByDesc('shares.updated_at')->orderBy('documents.id')->paginate($filters['per_page'] ?? 24, [
                'documents.id', 'documents.title', 'documents.theme', 'documents.kind', 'documents.updated_at', 'documents.created_by', 'shares.permission',
            ]);
        $owners = $this->users->findMany($records->getCollection()->pluck('created_by')->all());

        $data = $records->getCollection()->map(fn ($document) => [
            'id' => $document->id, 'title' => $document->title, 'theme' => $document->theme, 'kind' => $document->kind,
            'permission' => $document->permission, 'owner' => $owners[$document->created_by]['name'] ?? 'Преподаватель', 'updated_at' => $document->updated_at,
        ])->all();

        return ['data' => $data, 'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()];
    }

    public function viewer(string $documentId): array
    {
        return DB::transaction(function () use ($documentId) {
            $access = $this->access($documentId, true);
            $document = $access['document'];
            $blocks = DB::table('practice_blocks')->where('document_id', $document->id)->orderBy('position')->get();
            $rows = DB::table('practice_rows')->whereIn('block_id', $blocks->pluck('id'))->orderBy('position')->get();
            $cells = DB::table('practice_cells')->whereIn('row_id', $rows->pluck('id'))->orderBy('position')->get();
            $snapshots = DB::table('practice_card_snapshots')->where('workspace_id', $document->workspace_id)
                ->whereIn('id', $cells->pluck('card_snapshot_id')->filter()->unique())->get()->keyBy('id');
            $owner = $this->users->findMany([$document->created_by]);
            $channels = $access['owner'] ? $this->shares($document->id) : [];

            return [
                'document' => [
                    'id' => $document->id, 'title' => $document->title, 'theme' => $document->theme, 'kind' => $document->kind,
                    'revision' => (int) $document->revision, 'note' => null, 'practice_date' => null,
                    'fragment_kind' => $document->fragment_kind, 'illustration_style' => $document->illustration_style, 'illustration_character' => $document->illustration_character,
                    'blocks' => $blocks->map(fn ($block) => [
                        'id' => $block->id, 'title' => $block->title, 'purpose' => $block->purpose,
                        'rows' => $rows->where('block_id', $block->id)->map(fn ($row) => [
                            'id' => $row->id, 'note' => $row->note,
                            'cells' => $cells->where('row_id', $row->id)->map(function ($cell) use ($snapshots, $document) {
                                $snapshot = $cell->card_snapshot_id ? $snapshots[$cell->card_snapshot_id] ?? null : null;

                                return ExecutionParameters::values($cell) + [
                                    'id' => $cell->id, 'card_snapshot_id' => $cell->card_snapshot_id, 'custom_name' => $cell->custom_name,
                                    'group_key' => $cell->group_key, 'arrow_direction' => $cell->arrow_direction, 'note' => $cell->note,
                                    'card' => $snapshot ? ['id' => $snapshot->id, 'name' => $snapshot->name,
                                        'image_url' => '/api/shared-practices/'.$document->id.'/media/'.$snapshot->image_asset_id] : null,
                                ];
                            })->values()->all(),
                        ])->values()->all(),
                    ])->values()->all(),
                ],
                'access' => [
                    'can_copy' => ! $access['owner'] && (bool) ($access['share']->allow_copy ?? false),
                    'owner' => $access['owner'], 'can_comment' => $access['owner'] || $access['share']->permission === 'comment',
                    'share_id' => $access['share']?->id, 'owner_name' => $owner[$document->created_by]['name'] ?? 'Преподаватель', 'channels' => $channels,
                ],
                'comments' => $this->comments($access),
            ];
        });
    }

    public function addComment(string $documentId, array $input): array
    {
        return DB::transaction(function () use ($documentId, $input) {
            $access = $this->access($documentId, true);
            $document = $access['document'];
            $this->documentRevision($document, $input['expected_document_revision']);
            if ($access['owner']) {
                $share = DB::table('practice_document_shares')->where('document_id', $document->id)->where('id', $input['share_id'] ?? null)->whereNull('revoked_at')->first();
                if (! $share) {
                    throw ValidationException::withMessages(['share_id' => 'Выберите преподавателя с действующим доступом.']);
                }
            } else {
                $share = $access['share'];
                abort_unless($share->permission === 'comment', 403, 'Владелец разрешил только просмотр этой практики.');
                abort_if(isset($input['share_id']) && $input['share_id'] !== $share->id, 404);
            }
            $body = trim($input['body']);
            if ($body === '') {
                throw ValidationException::withMessages(['body' => 'Введите комментарий.']);
            }
            $context = null;
            if (! empty($input['cell_id'])) {
                $cell = DB::table('practice_cells as cells')->join('practice_rows as rows', 'rows.id', '=', 'cells.row_id')
                    ->join('practice_blocks as blocks', 'blocks.id', '=', 'rows.block_id')
                    ->where('blocks.document_id', $document->id)->where('cells.id', $input['cell_id'])
                    ->first(['cells.position as cell_position', 'rows.position as row_position', 'blocks.position as block_position']);
                if (! $cell) {
                    throw ValidationException::withMessages(['cell_id' => 'Ячейка уже удалена или находится в другой практике. Обновите лист.']);
                }
                $context = 'Ячейка '.($cell->block_position + 1).'.'.($cell->row_position + 1).'.'.($cell->cell_position + 1);
            }
            DB::table('practice_document_comments')->insert([
                'id' => (string) Str::uuid(), 'document_id' => $document->id, 'share_id' => $share->id,
                'author_user_id' => $this->workspace->userId(), 'body' => $body,
                'cell_id' => $input['cell_id'] ?? null, 'context' => $context, 'document_revision' => $document->revision,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->viewer($document->id);
        });
    }

    public function changeComment(string $documentId, string $commentId, array $input, bool $delete = false): array
    {
        return DB::transaction(function () use ($documentId, $commentId, $input, $delete) {
            $access = $this->access($documentId, true);
            $comment = DB::table('practice_document_comments')->where('document_id', $access['document']->id)
                ->where('id', $commentId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($comment && (int) $comment->author_user_id === $this->workspace->userId(), 404);
            if (! $access['owner']) {
                abort_unless($access['share']->id === $comment->share_id, 404);
                abort_unless($access['share']->permission === 'comment', 403, 'Владелец разрешил только просмотр этой практики.');
            }
            abort_unless((int) $comment->revision === (int) $input['expected_revision'], 409, 'Комментарий изменён в другом окне. Обновите страницу.');
            $changes = ['revision' => $comment->revision + 1, 'updated_at' => now()];
            if ($delete) {
                $changes['deleted_at'] = now();
            } else {
                $body = trim($input['body']);
                if ($body === '') {
                    throw ValidationException::withMessages(['body' => 'Введите комментарий.']);
                }
                $changes['body'] = $body;
            }
            DB::table('practice_document_comments')->where('id', $comment->id)->update($changes);

            return $this->viewer($access['document']->id);
        });
    }

    private function documentRevision(object $document, int $revision): void
    {
        abort_unless((int) $document->revision === $revision, 409, 'Практика изменена в другом окне. Обновите лист или список доступов.');
    }

    private function shares(string $documentId): array
    {
        $records = DB::table('practice_document_shares')->where('document_id', $documentId)->orderBy('created_at')->get();
        $users = $this->users->findMany($records->pluck('recipient_user_id')->all());

        return $records->map(fn ($share) => [
            'id' => $share->id, 'recipient' => $users[$share->recipient_user_id] ?? ['name' => 'Преподаватель', 'login' => ''],
            'permission' => $share->permission, 'revision' => (int) $share->revision, 'revoked_at' => $share->revoked_at,
            'allow_copy' => (bool) $share->allow_copy,
        ])->all();
    }

    private function comments(array $access): array
    {
        $query = DB::table('practice_document_comments')->where('document_id', $access['document']->id)->whereNull('deleted_at');
        if (! $access['owner']) {
            $query->where('share_id', $access['share']->id);
        }
        $records = $query->orderBy('created_at')->orderBy('id')->get();
        $users = $this->users->findMany($records->pluck('author_user_id')->all());

        return $records->map(fn ($comment) => [
            'id' => $comment->id, 'share_id' => $comment->share_id, 'body' => $comment->body,
            'context' => $comment->context, 'cell_id' => $comment->cell_id, 'document_revision' => (int) $comment->document_revision,
            'revision' => (int) $comment->revision, 'created_at' => $comment->created_at, 'updated_at' => $comment->updated_at,
            'author' => $users[$comment->author_user_id]['name'] ?? 'Преподаватель',
            'can_edit' => (int) $comment->author_user_id === $access['viewer_id'] && ($access['owner'] || $access['share']->permission === 'comment'),
        ])->all();
    }
}
