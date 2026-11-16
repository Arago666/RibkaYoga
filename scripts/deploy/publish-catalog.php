<?php

// Explicit operator publication: verified package + maintenance + fresh backup required for apply.
use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Http\AsanaController;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Services\DrawingSource;
use App\Modules\Catalog\Services\Facets;
use App\Modules\Catalog\Services\Names;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $options['apply'] = true;
    } elseif (preg_match('/^--(package|backup)=(.+)$/D', $argument, $match)) {
        $options[$match[1]] = $match[2];
    } else {
        throw new RuntimeException('Unknown operator argument');
    }
}
$root = isset($options['package']) ? realpath($options['package']) : false;
if (! $root) {
    throw new RuntimeException('Package is missing');
}
$manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $path => $hash) {
    if (str_contains($path, '..') || str_starts_with($path, '/') || is_link($root.'/'.$path) || ! is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $hash) {
        throw new RuntimeException('Package file mismatch');
    }
}
$plans = json_decode(file_get_contents($root.'/curation.json'), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($manifest['actor_id']);
Auth::setUser($user);
$workspace = app(WorkspaceContext::class)->id();
if ($workspace !== $manifest['workspace_id'] || count($plans) !== $manifest['cards']) {
    throw new RuntimeException('Workspace mismatch');
}
$source = app(DrawingSource::class);
$categories = DB::table('catalog_categories')->where('workspace_id', $workspace)->whereNotNull('system_key')->get()->keyBy('system_key');
foreach ($plans as $p) {
    $a = Asana::findOrFail($p['id']);
    if ($a->workspace_id !== $workspace || $a->revision !== $p['expected_revision'] || $a->current_image_asset_id !== $p['expected_image_asset_id']) {
        throw new RuntimeException('Catalog changed: '.$p['id']);
    }
    $source->validate(file_get_contents($root.'/artwork/'.$p['id'].'.json'));
}
if (! isset($options['apply'])) {
    echo 'Preflight: '.count($plans).' cards, revisions, source drawings and file hashes verified.'.PHP_EOL;
    exit;
}
$backup = realpath($options['backup'] ?? '') ?: throw new RuntimeException('Backup is missing');
if (DB::getDriverName() !== 'pgsql' || ! is_file(storage_path('framework/down')) || ! is_file($backup.'/backup-ready') || ! is_file($backup.'/database.dump') || filesize($backup.'/database.dump') < 1000) {
    throw new RuntimeException('Publication requires PostgreSQL, maintenance and a complete fresh backup');
}
$map = [];
$snapshots = [];
DB::transaction(function () use ($plans, $root, $categories, $workspace, $user, &$map, &$snapshots) {
    DB::table('catalog_asanas')->whereIn('id', array_column($plans, 'id'))->orderBy('id')->lockForUpdate()->get();
    foreach ($plans as $p) {
        $current = Asana::findOrFail($p['id']);
        if ($current->revision !== $p['expected_revision'] || $current->current_image_asset_id !== $p['expected_image_asset_id']) {
            throw new RuntimeException('Revision changed during publication');
        }
    }
    foreach ($plans as $p) {
        $a = Asana::findOrFail($p['id']);
        $facets = [];
        foreach (Facets::DIMENSIONS as $dimension) {
            $facets[$dimension] = $a->categories->where('dimension', $dimension)->pluck('id')->all();
            foreach ($p['facets'][$dimension] ?? [] as $value) {
                $cat = $categories->get($dimension.'.'.$value);
                if (! $cat) {
                    throw new RuntimeException('Unknown facet '.$value);
                }$facets[$dimension][] = $cat->id;
            }
            $facets[$dimension] = array_values(array_unique($facets[$dimension]));
        }
        $aliases = [];
        foreach ($p['fields']['aliases'] as $alias) {
            $key = Names::normalize($alias);
            $aliases[$key] = $alias;
        }
        $fields = [...$p['fields'], 'aliases' => array_values($aliases), 'expected_revision' => $p['expected_revision'], 'facet_assignments' => $facets, 'drawing_source' => file_get_contents($root.'/artwork/'.$p['id'].'.json')];
        $request = Request::create('/api/asanas/'.$a->id, 'POST', $fields, [], ['image' => new UploadedFile($root.'/artwork/'.$a->id.'.png', 'thin-'.$a->id.'.png', 'image/png', UPLOAD_ERR_OK, true)]);
        $request->setUserResolver(fn () => $user);
        app(AsanaController::class)->update($request, $a->id);
        $new = Asana::findOrFail($a->id);
        $map[$a->id] = ['image_asset_id' => $new->current_image_asset_id, 'name' => $new->name, 'old_asset_id' => $p['expected_image_asset_id']];
    }
    // Explicit image replacement for this reserved publication; structure, notes and group/arrow positions stay intact.
    foreach (DB::table('practice_card_snapshots')->where('workspace_id', $workspace)->get() as $snap) {
        if (! isset($map[$snap->source_asana_id])) {
            continue;
        }$replacement = $map[$snap->source_asana_id];
        DB::table('practice_card_snapshots')->where('id', $snap->id)->update(['image_asset_id' => $replacement['image_asset_id']]);
        $snapshots[$snap->id] = $replacement['image_asset_id'];
    }
    DB::table('practice_documents')->where('workspace_id', $workspace)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    foreach (DB::table('practice_reviews')->where('workspace_id', $workspace)->get() as $review) {
        $snapshot = json_decode($review->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $assets = [];
        foreach ($snapshot['blocks'] as &$block) {
            foreach ($block['rows'] as &$row) {
                foreach ($row['cells'] as &$cell) {
                    if (! empty($cell['card']) && isset($snapshots[$cell['card']['id']])) {
                        $cell['card']['image_asset_id'] = $snapshots[$cell['card']['id']];
                    }
                    if (! empty($cell['card']['image_asset_id'])) {
                        $assets[$cell['card']['image_asset_id']] = true;
                    }
                }
            }
        }
        unset($block,$row,$cell);
        DB::table('practice_reviews')->where('id', $review->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'revision' => $review->revision + 1, 'updated_at' => now()]);
        DB::table('practice_review_assets')->where('review_id', $review->id)->delete();
        foreach (array_keys($assets) as $id) {
            DB::table('practice_review_assets')->insert(['review_id' => $review->id, 'image_asset_id' => $id]);
        }
    }
});
file_put_contents($backup.'/applied-catalog.json', json_encode(['cards' => $map, 'snapshots' => count($snapshots), 'applied_at' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Updated '.count($map).' cards and '.count($snapshots).' snapshots; old files preserved.'.PHP_EOL;
