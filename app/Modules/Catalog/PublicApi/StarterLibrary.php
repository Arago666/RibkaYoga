<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\DrawingSource;
use App\Modules\Catalog\Services\Facets;
use App\Modules\Catalog\Services\Names;
use App\Modules\Catalog\Services\StarterClassification;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\NativeImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StarterLibrary
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private CollectionMembership $collections) {}

    public function copyToWorkspace(string $workspaceId): void
    {
        abort_unless($workspaceId === $this->workspace->id(), 403);
        app(Facets::class)->ensure($workspaceId);
        if (Asana::where('workspace_id', $workspaceId)->exists()) {
            return;
        }
        $root = database_path('seeders/snapshot');
        if (! is_file($root.'/dataset.json.gz')) {
            return;
        }
        $tables = json_decode(gzdecode(file_get_contents($root.'/dataset.json.gz')), true, 512, JSON_THROW_ON_ERROR)['tables'];
        $pending = [];
        try {
            DB::transaction(function () use ($workspaceId, $root, $tables, &$pending) {
                $this->collections->lockWorkspace();
                if (Asana::where('workspace_id', $workspaceId)->exists()) {
                    return;
                }
                $folder = $this->collections->starterCollection();
                $categories = [];
                $system = Category::where('workspace_id', $workspaceId)->whereNotNull('system_key')->get()->keyBy('system_key');
                foreach ($tables['catalog_categories'] as $source) {
                    if (! empty($source['system_key']) && isset($system[$source['system_key']])) {
                        $categories[$source['id']] = $system[$source['system_key']]->id;

                        continue;
                    }
                    $category = Category::firstOrCreate(['workspace_id' => $workspaceId, 'name' => $source['name'], 'dimension' => null], ['revision' => 1, 'parent_id' => null]);
                    $categories[$source['id']] = $category->id;
                }
                $images = array_column($tables['media_assets'], null, 'id');
                $mapping = [];
                $classifier = app(StarterClassification::class);
                foreach ($tables['catalog_asanas'] as $source) {
                    if ($source['archived_at']) {
                        continue;
                    }
                    foreach (['drawing_source', 'steps', 'teacher_cues', 'equipment', 'precautions', 'adaptations', 'editorial'] as $field) {
                        if (is_string($source[$field] ?? null)) {
                            $source[$field] = json_decode($source[$field], true, 512, JSON_THROW_ON_ERROR);
                        }
                    }
                    $image = $images[$source['current_image_asset_id']];
                    $path = $root.'/files/private/'.$image['original_path'];
                    if (! is_file($path)) {
                        throw new \RuntimeException('В базовой библиотеке отсутствует изображение.');
                    }
                    $drawing = isset($source['drawing_source']) ? app(DrawingSource::class)->validate($source['drawing_source']) : null;
                    $classification = empty($source['classification_status']) || $source['classification_status'] === 'unreviewed'
                        ? $classifier->forSource($source, $path, $drawing) : null;
                    if ($classification) {
                        $source['classification_status'] = $classification['classification_status'];
                        $source['editorial'] = array_replace($source['editorial'] ?? [], ['review_note' => trim(($source['editorial']['review_note'] ?? '')."\n".($classification['review_note'] ?? ''))]);
                    }
                    $asset = $this->assets->store(new UploadedFile($path, $image['original_filename'], null, null, true));
                    $pending[] = $asset['id'];
                    $aliases = array_column(array_filter($tables['catalog_asana_aliases'], fn ($a) => $a['asana_id'] === $source['id']), 'name');
                    $asana = Asana::create(['workspace_id' => $workspaceId, 'name' => $source['name'], 'normalized_name' => Names::normalize($source['name']), 'normalized_search_text' => Names::normalize(implode(' ', [$source['name'], $source['sanskrit_cyrillic'] ?? '', $source['sanskrit_latin'] ?? '', ...$aliases])), 'current_image_asset_id' => $asset['id'], 'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId(), 'revision' => 1, 'drawing_source' => $drawing, 'sanskrit_cyrillic' => $source['sanskrit_cyrillic'] ?? null, 'sanskrit_latin' => $source['sanskrit_latin'] ?? null, 'steps' => $source['steps'] ?? null, 'teacher_cues' => $source['teacher_cues'] ?? null, 'equipment' => $source['equipment'] ?? null, 'precautions' => $source['precautions'] ?? null, 'adaptations' => $source['adaptations'] ?? null, 'editorial' => $source['editorial'] ?? null, 'has_bind' => $source['has_bind'] ?? null, 'classification_status' => $source['classification_status'] ?? 'unreviewed']);
                    $mapping[$source['id']] = $asana->id;
                    if ($asana->drawing_source !== null) {
                        app(NativeImages::class)->remember($asset['id'], $asana->drawing_source);
                    }
                    $asana->aliases()->createMany(array_map(fn ($name) => ['name' => $name, 'normalized_name' => Names::normalize($name)], $aliases));
                    $sourceCategories = array_column(array_filter($tables['catalog_asana_category'], fn ($a) => $a['asana_id'] === $source['id']), 'category_id');
                    $asana->categories()->sync(array_map(fn ($id) => $categories[$id], $sourceCategories));
                    if ($classification) {
                        $assignments = [];
                        foreach (Facets::DIMENSIONS as $dimension) {
                            $assignments[$dimension] = array_map(fn ($key) => $system[$key]->id, $classification['facet_assignments'][$dimension] ?? []);
                        }
                        app(Facets::class)->sync($asana, $assignments);
                    }
                    $this->collections->addAsana($asana->id, [$folder]);
                }
                // Resolve relationships only after every active source has a new workspace UUID.
                foreach ($tables['catalog_asana_relations'] ?? [] as $relation) {
                    if (isset($mapping[$relation['asana_id']], $mapping[$relation['related_asana_id']])) {
                        DB::table('catalog_asana_relations')->insertOrIgnore(['asana_id' => $mapping[$relation['asana_id']], 'related_asana_id' => $mapping[$relation['related_asana_id']], 'kind' => $relation['kind']]);
                    }
                }
            });
        } catch (\Throwable $error) {
            foreach ($pending as $id) {
                $this->assets->discardUnattached($id);
            }
            throw $error;
        }
    }

    public function organizeExisting(): int
    {
        return DB::transaction(function () {
            $this->collections->lockWorkspace();
            $asanas = Asana::where('workspace_id', $this->workspace->id())->whereNull('archived_at')->orderBy('id')->get();
            $ids = $this->collections->collectionIdsForAsanas($asanas->modelKeys());
            $missing = $asanas->filter(fn ($a) => empty($ids[$a->id]));
            if ($missing->isEmpty()) {
                return 0;
            }
            $collection = $this->collections->starterCollection();
            foreach ($missing as $asana) {
                $this->collections->addAsana($asana->id, [$collection]);
            }

            return $missing->count();
        });
    }
}
