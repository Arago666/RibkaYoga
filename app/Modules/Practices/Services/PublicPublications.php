<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\PublicApi\ExecutionDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicPublications
{
    public function __construct(private WorkspaceContext $workspace, private PracticeEditor $editor, private DocumentDelivery $delivery, private ExecutionDocuments $execution) {}

    public function listing(string $documentId): array
    {
        $this->editor->document($documentId);

        return DB::table('practice_publications')->where('workspace_id', $this->workspace->id())->where('document_id', $documentId)->orderByDesc('created_at')->get(['id', 'revision', 'allow_copy', 'expires_at', 'revoked_at', 'created_at', 'options'])->map(fn ($row) => array_replace((array) $row, ['allow_copy' => (bool) $row->allow_copy, 'options' => json_decode($row->options ?? '{}', true)]))->all();
    }

    public function create(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $document = $this->editor->document($id);
            abort_if($document['archived_at'], 422, 'Восстановите практику перед публикацией.');
            abort_unless($document['revision'] === (int) $input['expected_revision'], 409, 'Практика изменилась. Проверьте публикуемый лист ещё раз.');
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $snapshots = $this->delivery->snapshotRows($document);
            $assetIds = array_values(array_unique(array_map(fn ($row) => $row->image_asset_id, $snapshots)));
            $plan = $this->execution->plan($document['id']);
            $notes = (bool) ($input['include_notes'] ?? false);
            $public = ['id' => $id, 'kind' => 'practice', 'title' => $document['title'], 'theme' => $notes ? $document['theme'] : null, 'note' => null, 'practice_date' => null, 'illustration_style' => $document['illustration_style'] ?? null, 'illustration_character' => $document['illustration_character'] ?? null, 'revision' => $document['revision'], 'blocks' => []];
            foreach ($document['blocks'] as $block) {
                $publicBlock = ['id' => $block['id'], 'title' => $notes ? $block['title'] : '', 'purpose' => $notes ? $block['purpose'] : null, 'rows' => []];
                foreach ($block['rows'] as $row) {
                    $rowNote = $notes || preg_match('/^Подчёркнуто:\s*[\d\s–;]+$/u', $row['note'] ?? '') ? $row['note'] : $this->signs($row['note']);
                    $publicRow = ['id' => $row['id'], 'note' => $rowNote, 'cells' => []];
                    foreach ($row['cells'] as $cell) {
                        $content = array_intersect_key($cell, array_flip(['id', 'card_snapshot_id', 'custom_name', 'group_key', 'arrow_direction', ...ExecutionParameters::FIELDS]));
                        $content['note'] = $notes ? $cell['note'] : $this->signs($cell['note']);
                        $snapshot = $snapshots[$cell['card_snapshot_id'] ?? ''] ?? null;
                        $content['card'] = $snapshot ? ['id' => $snapshot->id, 'name' => $snapshot->name, 'image_url' => '@publication/'.$snapshot->image_asset_id] : null;
                        $publicRow['cells'][] = $content;
                    }
                    $publicBlock['rows'][] = $publicRow;
                }
                $public['blocks'][] = $publicBlock;
            }
            foreach ($plan['steps'] as &$step) {
                $cell = collect($public['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => $row['cells']))->firstWhere('id', $step['cell_id']);
                $step['image_url'] = $cell['card']['image_url'] ?? null;
                if (! $notes) {
                    $step['note'] = null;
                }
            }
            unset($step);
            $plan['document_id'] = $id;
            $expires = empty($input['expires_at']) ? null : CarbonImmutable::parse($input['expires_at'])->utc();
            abort_if($expires && ($expires->isPast() || $expires->greaterThan(now()->addYear())), 422, 'Срок ссылки должен быть в ближайший год.');
            $options = ['show_names' => (bool) ($input['show_names'] ?? false), 'include_notes' => $notes, 'orientation' => $input['orientation'] ?? 'landscape'];
            DB::table('practice_publications')->insert(['id' => $id, 'workspace_id' => $this->workspace->id(), 'document_id' => $document['id'], 'created_by' => $this->workspace->userId(), 'token_hash' => hash('sha256', $token), 'snapshot' => json_encode(['format' => 'ribka-public-practice-v1', 'document' => $public, 'plan' => $plan], JSON_THROW_ON_ERROR), 'asset_ids' => json_encode($assetIds, JSON_THROW_ON_ERROR), 'allow_copy' => (bool) ($input['allow_copy'] ?? false), 'expires_at' => $expires, 'revoked_at' => null, 'revision' => 1, 'options' => json_encode($options, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['id' => $id, 'revision' => 1, 'url' => url('/p/'.$token), 'token' => $token];
        });
    }

    public function preview(string $id, array $input): array
    {
        DB::beginTransaction();
        try {
            $publication = $this->create($id, $input);
            $data = $this->viewer($publication['token']);
            foreach ($data['document']['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        if ($cell['card']) {
                            $cell['card']['image_url'] = '/media/'.basename($cell['card']['image_url']);
                        }
                    }
                }
            }
            unset($block, $row, $cell);

            return $data;
        } finally {
            DB::rollBack();
        }
    }

    public function revoke(string $documentId, string $publicationId, int $revision): void
    {
        $this->editor->document($documentId);
        DB::transaction(function () use ($documentId, $publicationId, $revision) {
            $query = DB::table('practice_publications')->where('workspace_id', $this->workspace->id())->where('document_id', $documentId)->where('id', $publicationId);
            $row = $query->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === $revision, 409, 'Доступ уже изменён. Обновите список.');
            $query->update(['revoked_at' => now(), 'revision' => $revision + 1]);
        });
    }

    public function resolve(string $token): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $row = DB::table('practice_publications')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        abort_unless($row, 404, 'Ссылка недоступна или срок её действия истёк.');

        return $row;
    }

    public function viewer(string $token): array
    {
        $row = $this->resolve($token);
        $snapshot = json_decode($row->snapshot, true, 128, JSON_THROW_ON_ERROR);
        $replace = function (&$value) use (&$replace, $token) {
            if (is_array($value)) {
                foreach ($value as &$part) {
                    $replace($part);
                }
            } elseif (is_string($value) && str_starts_with($value, '@publication/')) {
                $value = '/api/published/'.$token.'/media/'.substr($value, strlen('@publication/'));
            }
        };
        $replace($snapshot);

        return ['document' => $snapshot['document'], 'plan' => $snapshot['plan'], 'options' => json_decode($row->options ?? '{}', true), 'allow_copy' => (bool) $row->allow_copy, 'expires_at' => $row->expires_at];
    }

    private function signs(?string $note): ?string
    {
        $parts = array_map('trim', preg_split('/[·\n]+/u', $note ?? '') ?: []);
        $signs = array_intersect($parts, ['S', '★', 'СВ*', '→', '↔', '=', '/']);

        return $signs ? implode(' · ', $signs) : null;
    }
}
