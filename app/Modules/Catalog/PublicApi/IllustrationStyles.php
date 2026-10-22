<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Illustrations\MannequinRenderer;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Media\PublicApi\AssetFingerprints;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Immutable alternative representations; never changes an asana or saved document. */
class IllustrationStyles
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private AssetFingerprints $fingerprints, private MannequinRenderer $renderer) {}

    public function state(): array
    {
        $preference = $this->preference();
        $pack = $this->pack();
        $cards = Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->get(['id', 'name', 'current_image_asset_id']);
        $ids = $cards->pluck('current_image_asset_id')->filter()->unique()->all();
        $hashes = $this->fingerprints->forMany($ids);
        $known = array_column($pack['verified_sources'], 'pose_key', 'source_sha256');
        $matched = $cards->filter(fn ($card) => isset($known[$hashes[$card->current_image_asset_id] ?? '']));
        $images = $this->assets->getMany($matched->pluck('current_image_asset_id')->unique()->all());

        return [
            'preference' => $this->preferenceData($preference),
            'coverage' => ['total' => $cards->count(), 'matched' => $matched->count(), 'original' => $cards->count(), 'line' => $matched->count(), 'volume' => $matched->count()],
            'cards' => $matched->map(fn ($card) => ['id' => $card->id, 'name' => $card->name, 'image_asset_id' => $card->current_image_asset_id, 'image_url' => $images[$card->current_image_asset_id]['image_url'], 'pose_key' => $known[$hashes[$card->current_image_asset_id]]])->values()->all(),
            'pack' => ['format' => $pack['format'], 'version' => $pack['version'], 'model_revision' => $pack['model_revision'], 'rights' => $pack['rights'], 'poses' => $pack['poses']],
        ];
    }

    public function savePreference(string $style, string $character, int $revision): array
    {
        $this->validateStyle($style, $character);
        $this->preference();

        return DB::transaction(function () use ($style, $character, $revision) {
            $query = $this->preferenceQuery();
            $record = $query->lockForUpdate()->first();
            abort_unless((int) $record->revision === $revision, 409, 'Настройки иллюстраций изменены в другом окне. Обновите страницу.');
            $query->update(['style' => $style, 'character' => $character, 'revision' => $revision + 1, 'updated_at' => now()]);

            return ['style' => $style, 'character' => $character, 'revision' => $revision + 1];
        });
    }

    public function preferred(): array
    {
        return $this->preferenceData($this->preference());
    }

    /** Historical pictures are never replaced merely because their asana ID matches. */
    public function prepareForSnapshot(string $asanaId, string $imageAssetId, string $style, string $character): array
    {
        $this->validateStyle($style, $character);
        $original = $this->assets->get($imageAssetId);
        $card = Asana::where('workspace_id', $this->workspace->id())->find($asanaId);
        $sourceId = $imageAssetId;
        if ($card && $card->current_image_asset_id !== $imageAssetId) {
            $previousVariant = DB::table('catalog_illustration_variants')->where('workspace_id', $this->workspace->id())->where('asana_id', $asanaId)->where('image_asset_id', $imageAssetId)->where('source_asset_id', $card->current_image_asset_id)->first();
            $sourceId = $previousVariant?->source_asset_id;
        }
        if (! $card || $card->archived_at || $sourceId !== $card->current_image_asset_id) {
            return ['available' => false, 'fallback' => true, 'style' => 'original', 'image_asset_id' => $imageAssetId, 'image_url' => $original['image_url'], 'reason' => 'Историческая или недоступная версия позы: изображение сохранено без подмены.'];
        }

        return $this->prepare($asanaId, $sourceId, $style, $character);
    }

    /** Explicit request, pinned to the exact source image; unknown poses stay original. */
    public function prepare(string $asanaId, string $sourceAssetId, string $style, string $character = 'neutral'): array
    {
        $this->validateStyle($style, $character);
        $pendingAsset = null;
        try {
            return DB::transaction(function () use ($asanaId, $sourceAssetId, $style, $character, &$pendingAsset) {
                $card = Asana::where('workspace_id', $this->workspace->id())->lockForUpdate()->findOrFail($asanaId);
                abort_if($card->archived_at, 422, 'Сначала восстановите архивную асану.');
                abort_unless($card->current_image_asset_id === $sourceAssetId, 409, 'Исходный рисунок изменился. Сверьте карточку перед выбором другого представления.');
                $original = $this->assets->get($sourceAssetId);
                $hash = $this->fingerprints->forMany([$sourceAssetId])[$sourceAssetId];
                $pack = $this->pack();
                $mapping = collect($pack['verified_sources'])->firstWhere('source_sha256', $hash);
                if ($style === 'original' || ! $mapping) {
                    return ['available' => $style === 'original', 'fallback' => $style !== 'original', 'requested_style' => $style, 'style' => 'original', 'character' => $character, 'asana_id' => $asanaId, 'source_asset_id' => $sourceAssetId, 'image_asset_id' => $sourceAssetId, 'source_sha256' => $hash, 'image_url' => $original['image_url'], 'reason' => $style === 'original' ? null : 'Для этой точной версии позы нет проверенного варианта. Сохраняется исходный рисунок.'];
                }
                $query = DB::table('catalog_illustration_variants')->where('workspace_id', $this->workspace->id())->where('asana_id', $asanaId)->where('source_asset_id', $sourceAssetId)->where('style', $style)->where('character', $character)->where('model_revision', $pack['model_revision']);
                $variant = $query->first();
                if (! $variant) {
                    $pose = $pack['poses'][$mapping['pose_key']];
                    $rendered = $this->renderer->render($pose, $style, $character);
                    if ($rendered['drawing_source']) {
                        $rendered['drawing_source'] = app(DrawingSources::class)->validate($rendered['drawing_source']);
                    }
                    $temporary = tempnam(sys_get_temp_dir(), 'ribka-style-');
                    abort_unless(is_string($temporary), 500, 'Не удалось подготовить изображение.');
                    try {
                        file_put_contents($temporary, $rendered['png']);
                        $asset = $this->assets->store(new UploadedFile($temporary, $mapping['pose_key'].'-'.$style.'-'.$character.'.png', 'image/png', null, true));
                        $pendingAsset = $asset['id'];
                    } finally {
                        @unlink($temporary);
                    }
                    $renderSource = ['format' => 'ribka-mannequin', 'version' => 1, 'pose_key' => $mapping['pose_key'], 'model_revision' => $pack['model_revision'], 'style' => $style, 'character' => $character, 'pose' => $pose, 'source_sha256' => $hash, 'rights' => $pack['rights']];
                    DB::table('catalog_illustration_variants')->insert([
                        'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id(), 'asana_id' => $asanaId,
                        'source_asset_id' => $sourceAssetId, 'source_sha256' => $hash, 'image_asset_id' => $asset['id'],
                        'pose_key' => $mapping['pose_key'], 'style' => $style, 'character' => $character, 'model_revision' => $pack['model_revision'],
                        'render_source' => json_encode($renderSource, JSON_THROW_ON_ERROR), 'drawing_source' => $rendered['drawing_source'] ? json_encode($rendered['drawing_source'], JSON_THROW_ON_ERROR) : null,
                        'created_by' => $this->workspace->userId(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $variant = $query->first();
                }
                $image = $this->assets->get($variant->image_asset_id);

                return ['available' => true, 'fallback' => false, 'style' => $variant->style, 'character' => $variant->character, 'asana_id' => $asanaId, 'source_asset_id' => $sourceAssetId, 'image_asset_id' => $variant->image_asset_id, 'image_url' => $image['image_url'], 'source_sha256' => $variant->source_sha256, 'render_source' => json_decode($variant->render_source, true), 'drawing_source' => $variant->drawing_source ? json_decode($variant->drawing_source, true) : null, 'variant_id' => $variant->id];
            });
        } catch (\Throwable $error) {
            if ($pendingAsset) {
                $this->assets->discardUnattached($pendingAsset);
            }
            throw $error;
        }
    }

    /** Validate an explicit snapshot selection without silently using a global preference. */
    public function resolve(string $asanaId, string $sourceAssetId, ?string $variantId): array
    {
        if ($variantId === null) {
            $card = Asana::where('workspace_id', $this->workspace->id())->findOrFail($asanaId);
            abort_unless($card->current_image_asset_id === $sourceAssetId, 409, 'Изображение карточки изменилось. Обновите выбор.');

            return ['image_asset_id' => $sourceAssetId, 'drawing_source' => null, 'style' => 'original'];
        }
        $variant = DB::table('catalog_illustration_variants')->where('workspace_id', $this->workspace->id())->where('id', $variantId)->where('asana_id', $asanaId)->where('source_asset_id', $sourceAssetId)->first();
        abort_unless($variant, 404);
        $this->assets->get($variant->image_asset_id);

        return ['image_asset_id' => $variant->image_asset_id, 'drawing_source' => $variant->drawing_source ? json_decode($variant->drawing_source, true) : null, 'style' => $variant->style, 'render_source' => json_decode($variant->render_source, true)];
    }

    private function preferenceQuery(): Builder
    {
        return DB::table('catalog_illustration_preferences')->where('workspace_id', $this->workspace->id())->where('owner_id', $this->workspace->userId());
    }

    private function preference(): object
    {
        $this->preferenceQuery()->insertOrIgnore(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id(), 'owner_id' => $this->workspace->userId(), 'style' => 'original', 'character' => 'neutral', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $this->preferenceQuery()->first();
    }

    private function preferenceData(object $record): array
    {
        return ['style' => $record->style, 'character' => $record->character, 'revision' => (int) $record->revision];
    }

    private function validateStyle(string $style, string $character): void
    {
        validator(['style' => $style, 'character' => $character], ['style' => ['required', Rule::in(['original', 'line', 'volume'])], 'character' => ['required', Rule::in(['neutral', 'female', 'male'])]])->validate();
    }

    private function pack(): array
    {
        return json_decode(file_get_contents(resource_path('imports/illustration-styles/poses.json')), true, 64, JSON_THROW_ON_ERROR);
    }
}
