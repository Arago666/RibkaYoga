<?php

namespace App\Modules\Catalog\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\Names;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StarterLibrary
{
    public function __construct(private WorkspaceContext $workspace, private AssetStore $assets, private CollectionMembership $collections) {}

    public function copyToWorkspace(string $workspaceId): void
    {
        abort_unless($workspaceId === $this->workspace->id(), 403);
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
                $folder = $this->collections->starterCollection();
                $categories = [];
                foreach ($tables['catalog_categories'] as $source) {
                    $category = Category::firstOrCreate(['workspace_id' => $workspaceId, 'name' => $source['name']], ['revision' => 1, 'parent_id' => null]);
                    $categories[$source['id']] = $category->id;
                }
                $images = array_column($tables['media_assets'], null, 'id');
                foreach ($tables['catalog_asanas'] as $source) {
                    if ($source['archived_at']) {
                        continue;
                    }
                    $image = $images[$source['current_image_asset_id']];
                    $path = $root.'/files/private/'.$image['original_path'];
                    if (! is_file($path)) {
                        throw new \RuntimeException('В базовой библиотеке отсутствует изображение.');
                    }
                    $asset = $this->assets->store(new UploadedFile($path, $image['original_filename'], null, null, true));
                    $pending[] = $asset['id'];
                    $aliases = array_column(array_filter($tables['catalog_asana_aliases'], fn ($a) => $a['asana_id'] === $source['id']), 'name');
                    $asana = Asana::create(['workspace_id' => $workspaceId, 'name' => $source['name'], 'normalized_name' => Names::normalize($source['name']), 'normalized_search_text' => Names::normalize(implode(' ', [$source['name'], ...$aliases])), 'current_image_asset_id' => $asset['id'], 'created_by' => $this->workspace->userId(), 'updated_by' => $this->workspace->userId(), 'revision' => 1]);
                    $asana->aliases()->createMany(array_map(fn ($name) => ['name' => $name, 'normalized_name' => Names::normalize($name)], $aliases));
                    $sourceCategories = array_column(array_filter($tables['catalog_asana_category'], fn ($a) => $a['asana_id'] === $source['id']), 'category_id');
                    $asana->categories()->sync(array_map(fn ($id) => $categories[$id], $sourceCategories));
                    $this->collections->addAsana($asana->id, [$folder]);
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
