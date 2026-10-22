<?php

namespace App\Modules\Practices\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\Entitlements;
use App\Modules\Catalog\PublicApi\DrawingSources;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Media\PublicApi\PublishedImages;
use App\Modules\Practices\Actions\PracticeEditor;
use App\Modules\Practices\Http\Requests\DocumentRequest;
use App\Modules\Practices\PublicApi\NativeImages;
use App\Modules\Practices\PublicApi\SnapshotClassifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PortablePractices
{
    private const MAX_IMAGE_BYTES = 32 * 1024 * 1024;

    private const MAX_BUNDLE_BYTES = 44 * 1024 * 1024;

    public function __construct(private WorkspaceContext $workspace, private PracticeEditor $editor, private DocumentDelivery $delivery, private AssetStore $assets, private NativeImages $native, private DrawingSources $sources, private Entitlements $entitlements) {}

    public function export(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $document = $this->editor->document($id);
            $snapshots = $this->delivery->snapshotRows($document);
            $images = [];
            $cards = [];
            $bytes = 0;
            foreach ($snapshots as $row) {
                if (! isset($images[$row->image_asset_id])) {
                    $asset = $this->assets->originalData($row->image_asset_id);
                    $bytes += $asset['bytes'];
                    abort_if($bytes > self::MAX_IMAGE_BYTES, 422, 'Экспорт ограничен 32 МБ изображений. Сохраните меньший фрагмент.');
                    $imageBytes = base64_decode(explode(',', $asset['data_url'], 2)[1], true);
                    $source = $this->native->source($row->image_asset_id);
                    $images[$row->image_asset_id] = ['key' => $row->image_asset_id, 'image_data' => $asset['data_url'], 'sha256' => hash('sha256', $imageBytes), 'drawing_source' => $source, 'source_available' => $source !== null];
                }
                $cards[] = ['id' => $row->id, 'name' => $row->name, 'image_key' => $row->image_asset_id, 'classification_snapshot' => app(SnapshotClassifications::class)->fromStored($row->classification_snapshot ?? null)];
            }
            foreach ($document['blocks'] as &$block) {
                foreach ($block['rows'] as &$row) {
                    foreach ($row['cells'] as &$cell) {
                        unset($cell['card']);
                    }
                }
            }
            unset($block, $row, $cell);
            $bundle = ['format' => 'ribka-practice-bundle', 'version' => 1, 'exported_at' => now()->toISOString(), 'document' => $document, 'snapshots' => $cards, 'images' => array_values($images)];
            abort_if(strlen(json_encode($bundle, JSON_THROW_ON_ERROR)) > self::MAX_BUNDLE_BYTES, 422, 'Исходники делают пакет слишком большим. Экспортируйте меньший фрагмент.');

            return $bundle;
        });
    }

    public function preview(array $bundle): array
    {
        $this->validateBundle($bundle);
        $document = $bundle['document'];
        $rows = collect($document['blocks'])->flatMap(fn ($block) => $block['rows']);

        return ['title' => $document['title'], 'kind' => $document['kind'] ?? 'practice', 'blocks' => count($document['blocks']), 'rows' => $rows->count(), 'cells' => $rows->sum(fn ($row) => count($row['cells'])), 'images' => count($bundle['images']), 'native_sources' => count(array_filter($bundle['images'], fn ($image) => ! empty($image['drawing_source']))), 'missing_sources' => count(array_filter($bundle['images'], fn ($image) => empty($image['drawing_source'])))];
    }

    public function import(array $bundle, string $key, ?string $title = null): array
    {
        $this->validateBundle($bundle);
        $hash = hash('sha256', json_encode([$bundle, $title], JSON_THROW_ON_ERROR));
        $made = [];
        try {
            return DB::transaction(function () use ($bundle, $key, $title, $hash, &$made) {
                $this->entitlements->lock($this->workspace->id());
                $old = DB::table('practice_operations')->where('id', $key)->first();
                if ($old) {
                    abort_unless($old->workspace_id === $this->workspace->id() && (int) $old->user_id === $this->workspace->userId() && $old->kind === 'portable-import' && hash_equals($old->request_hash, $hash), 409, 'Ключ импорта уже использован другим пакетом.');

                    return $this->editor->document(json_decode($old->result, true)['document_id']);
                }
                $this->entitlements->assertCanCreate($this->workspace->id(), 'practices', DB::table('practice_documents')->where('workspace_id', $this->workspace->id())->whereNull('archived_at')->count());
                $assetIds = [];
                foreach ($bundle['images'] as $image) {
                    $asset = $this->storeImage($image['image_data']);
                    $made[] = $asset['id'];
                    $assetIds[$image['key']] = $asset['id'];
                    if (! empty($image['drawing_source'])) {
                        abort_unless($asset['mime'] === 'image/png' && (int) $asset['width'] === 1024 && (int) $asset['height'] === 1024, 422, 'Исходнику нужен PNG 1024 × 1024.');
                        $this->native->remember($asset['id'], $image['drawing_source']);
                    }
                }
                $snapshots = [];
                foreach ($bundle['snapshots'] as $snapshot) {
                    $newId = (string) Str::uuid();
                    $snapshots[strtolower($snapshot['id'])] = $newId;
                    DB::table('practice_card_snapshots')->insert(['id' => $newId, 'workspace_id' => $this->workspace->id(), 'source_asana_id' => null, 'name' => $snapshot['name'], 'image_asset_id' => $assetIds[$snapshot['image_key']], 'classification_snapshot' => app(SnapshotClassifications::class)->encode($snapshot['classification_snapshot'] ?? null), 'created_at' => now()]);
                }
                $tree = $bundle['document'];
                if ($title !== null && trim($title) !== '') {
                    $tree['title'] = trim($title);
                }
                foreach ($tree['blocks'] as &$block) {
                    foreach ($block['rows'] as &$row) {
                        foreach ($row['cells'] as &$cell) {
                            $cell['card_snapshot_id'] = empty($cell['card_snapshot_id']) ? null : $snapshots[strtolower($cell['card_snapshot_id'])];
                        }
                    }
                }
                unset($block, $row, $cell);
                $document = $this->editor->importTree($tree, $tree['kind'] ?? 'practice');
                DB::table('practice_operations')->insert(['id' => $key, 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'kind' => 'portable-import', 'request_hash' => $hash, 'result' => json_encode(['document_id' => $document['id']], JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return $document;
            });
        } catch (\Throwable $error) {
            foreach ($made as $assetId) {
                $this->assets->discardUnattached($assetId);
            }
            throw $error;
        }
    }

    public function copyShared(string $id, string $key, DocumentSharing $sharing): array
    {
        $access = $sharing->access($id);
        abort_unless(! $access['owner'] && $access['share']->allow_copy, 403, 'Владелец не разрешил копирование.');
        $document = $sharing->viewer($id)['document'];

        return $this->import($this->bundleFromShared($document, fn ($assetId) => $this->assets->forDocument($assetId, $id), $access['document']->workspace_id), $key);
    }

    public function copyPublished(string $token, string $key, PublicPublications $publications, PublishedImages $images): array
    {
        $publication = $publications->resolve($token);
        abort_unless($publication->allow_copy, 403, 'Владелец не разрешил копирование.');
        $document = $publications->viewer($token)['document'];
        $document['kind'] = 'practice';

        return $this->import($this->bundleFromShared($document, fn ($assetId) => $images->file($token, $assetId), $publication->workspace_id), $key);
    }

    private function bundleFromShared(array $document, callable $file, string $sourceWorkspace): array
    {
        $ids = collect($document['blocks'])->flatMap(fn ($block) => collect($block['rows'])->flatMap(fn ($row) => array_column($row['cells'], 'card_snapshot_id')))->filter()->unique()->all();
        $rows = DB::table('practice_card_snapshots')->where('workspace_id', $sourceWorkspace)->whereIn('id', $ids)->get();
        abort_unless($rows->count() === count($ids), 422);
        $images = [];
        $snapshots = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if (! isset($images[$row->image_asset_id])) {
                $media = $file($row->image_asset_id);
                $bytes += filesize($media['path']);
                abort_if($bytes > self::MAX_IMAGE_BYTES, 422, 'Копия превышает лимит медиа.');
                $contents = file_get_contents($media['path']);
                $images[$row->image_asset_id] = ['key' => $row->image_asset_id, 'image_data' => 'data:'.$media['mime'].';base64,'.base64_encode($contents), 'sha256' => hash('sha256', $contents), 'drawing_source' => null];
            }
            $snapshots[] = ['id' => $row->id, 'name' => $row->name, 'image_key' => $row->image_asset_id, 'classification_snapshot' => app(SnapshotClassifications::class)->fromStored($row->classification_snapshot ?? null)];
        }
        $document['title'] = mb_substr($document['title'], 0, 150).' — копия';
        foreach ($document['blocks'] as $index => &$block) {
            if (trim($block['title'] ?? '') === '') {
                $block['title'] = 'Блок '.($index + 1);
            }
        }
        unset($block);

        return ['format' => 'ribka-practice-bundle', 'version' => 1, 'document' => $document, 'snapshots' => $snapshots, 'images' => array_values($images)];
    }

    private function validateBundle(array $bundle): void
    {
        abort_if(strlen(json_encode($bundle, JSON_THROW_ON_ERROR)) > self::MAX_BUNDLE_BYTES, 422, 'Пакет превышает 44 МБ.');
        Validator::make($bundle, ['format' => 'required|in:ribka-practice-bundle', 'version' => 'required|integer|in:1', 'document' => 'required|array', 'document.kind' => 'sometimes|in:practice,template,fragment', 'snapshots' => 'present|array|list|max:1000', 'snapshots.*.id' => 'required|uuid|distinct:ignore_case', 'snapshots.*.name' => 'required|string|max:160', 'snapshots.*.image_key' => 'required|string|max:100', 'snapshots.*.classification_snapshot' => 'sometimes|nullable|array', 'images' => 'present|array|list|max:1000', 'images.*.key' => 'required|string|max:100|distinct', 'images.*.image_data' => 'required|string', 'images.*.sha256' => 'required|string|size:64', 'images.*.drawing_source' => 'sometimes|nullable|array'])->validate();
        $rules = (new DocumentRequest)->rules();
        unset($rules['expected_revision']);
        Validator::make($bundle['document'], $rules)->validate();
        $keys = [];
        $bytes = 0;
        foreach ($bundle['images'] as $image) {
            abort_unless(preg_match('/^data:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+\/=]+)$/D', $image['image_data'], $match), 422, 'Неподдерживаемое изображение в пакете.');
            $contents = base64_decode($match[2], true);
            $info = is_string($contents) ? @getimagesizefromstring($contents) : false;
            abort_unless($info && ($info['mime'] ?? null) === $match[1] && hash_equals(strtolower($image['sha256']), hash('sha256', $contents)), 422, 'Изображение повреждено или контрольная сумма не совпала.');
            $bytes += strlen($contents);
            abort_if($bytes > self::MAX_IMAGE_BYTES || strlen($contents) > 10 * 1024 * 1024, 422, 'Превышен лимит изображений.');
            if (! empty($image['drawing_source'])) {
                $this->sources->validate($image['drawing_source']);
                abort_unless($info[0] === 1024 && $info[1] === 1024 && $match[1] === 'image/png', 422, 'Исходнику нужен PNG 1024 × 1024.');
            }
            $keys[$image['key']] = true;
        }
        $snapshots = [];
        foreach ($bundle['snapshots'] as $snapshot) {
            app(SnapshotClassifications::class)->validate($snapshot['classification_snapshot'] ?? null);
            abort_unless(isset($keys[$snapshot['image_key']]), 422, 'В пакете отсутствует нужное изображение.');
            $snapshots[strtolower($snapshot['id'])] = true;
        }
        $count = 0;
        foreach ($bundle['document']['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    $count++;
                    abort_if(! empty($cell['card_snapshot_id']) && ! isset($snapshots[strtolower($cell['card_snapshot_id'])]), 422, 'В пакете отсутствует снимок карточки.');
                    ExecutionParameters::values($cell);
                }
            }
        }
        abort_if($count > 1000, 422, 'В документе больше 1000 ячеек.');
    }

    private function storeImage(string $dataUrl): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ribka-bundle-');
        abort_unless($path, 500);
        try {
            file_put_contents($path, base64_decode(explode(',', $dataUrl, 2)[1], true));

            return $this->assets->store(new UploadedFile($path, 'import.png', null, null, true));
        } finally {
            @unlink($path);
        }
    }
}
