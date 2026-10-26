<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\PublicApi\IllustrationStyles;
use App\Modules\Collections\PublicApi\CollectionMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogQuery
{
    public function __construct(private WorkspaceContext $workspace, private CatalogReader $reader, private Facets $facets, private CollectionMembership $collections) {}

    public function input(Request $request): array
    {
        $this->facets->ensure($this->workspace->id());
        $rules = [
            'q' => ['nullable', 'string', 'max:200'], 'category' => ['nullable', 'uuid'], 'collection' => ['nullable', 'uuid'],
            'categories' => ['sometimes', 'array', 'list', 'max:100'], 'categories.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'archive' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'image_style' => ['nullable', Rule::in(['original', 'volume'])],
            'sort' => ['nullable', Rule::in(['name_asc', 'name_desc', 'recent', 'used_recently'])],
            'favorite' => ['nullable', 'boolean'], 'unclassified' => ['nullable', 'boolean'],
            'has_bind' => ['nullable', Rule::in(['yes', 'no', 'unknown'])], 'equipment' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:96'],
        ];
        foreach (Facets::DIMENSIONS as $dimension) {
            $rules[$dimension] = ['sometimes', 'array', 'list', 'max:'.($dimension === 'difficulty' ? 1 : count(Facets::VALUES[$dimension]))];
            $rules[$dimension.'.*'] = ['required', 'uuid', 'distinct:ignore_case'];
        }
        $input = $request->validate($rules);
        $assignments = array_intersect_key($input, array_flip(Facets::DIMENSIONS));
        $input = array_replace($input, $this->facets->validate($assignments, $this->workspace->id()));
        if (! empty($input['category']) && ! Category::where('workspace_id', $this->workspace->id())->where('id', $input['category'])->exists()) {
            throw ValidationException::withMessages(['category' => 'Категория недоступна.']);
        }
        if (! empty($input['categories']) && Category::where('workspace_id', $this->workspace->id())->whereIn('id', $input['categories'])->count() !== count($input['categories'])) {
            throw ValidationException::withMessages(['categories' => 'Категории недоступны.']);
        }
        if (! empty($input['collection'])) {
            $input['_source_ids'] = $this->collections->asanaIdsForSource($input['collection']);
        }

        return $input;
    }

    public function query(array $input, ?string $ignoreDimension = null): Builder
    {
        $query = Asana::query()->where('workspace_id', $this->workspace->id());
        match ($input['archive'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'), 'all' => null, default => $query->whereNull('archived_at'),
        };
        if (! empty($input['q'])) {
            $query->where('normalized_search_text', 'like', Names::like($input['q']));
        }
        if (isset($input['_source_ids'])) {
            $query->whereIn('id', $input['_source_ids']);
        }
        if (! empty($input['category'])) {
            $query->whereHas('categories', fn ($categories) => $categories->where('catalog_categories.id', $input['category']));
        }
        if (! empty($input['categories'])) {
            $query->whereHas('categories', fn ($categories) => $categories->whereIn('catalog_categories.id', $input['categories']));
        }
        foreach (Facets::DIMENSIONS as $dimension) {
            if ($dimension !== $ignoreDimension && ! empty($input[$dimension])) {
                $query->whereHas('categories', fn ($categories) => $categories->where('dimension', $dimension)->whereIn('catalog_categories.id', $input[$dimension]));
            }
        }
        if (! empty($input['favorite']) || ($input['sort'] ?? '') === 'used_recently') {
            $query->whereExists(function ($preferences) use ($input) {
                $preferences->selectRaw('1')->from('catalog_asana_preferences')->whereColumn('asana_id', 'catalog_asanas.id')
                    ->where('catalog_asana_preferences.workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
                if (! empty($input['favorite'])) {
                    $preferences->where('favorite', true);
                }
                if (($input['sort'] ?? '') === 'used_recently') {
                    $preferences->whereNotNull('last_used_at');
                }
            });
        }
        if (! empty($input['unclassified'])) {
            $query->where(function ($missing) {
                $missing->where('classification_status', '!=', 'reviewed');
                foreach (Facets::DIMENSIONS as $dimension) {
                    $missing->orWhereDoesntHave('categories', fn ($categories) => $categories->where('dimension', $dimension));
                }
            });
        }
        if (! empty($input['has_bind'])) {
            match ($input['has_bind']) {
                'yes' => $query->where('has_bind', true), 'no' => $query->where('has_bind', false), default => $query->whereNull('has_bind'),
            };
        }
        if (! empty($input['equipment'])) {
            $query->whereJsonContains('equipment', $input['equipment']);
        }

        return $query;
    }

    public function response(array $input): array
    {
        $query = $this->query($input)->select(CatalogReader::SUMMARY_COLUMNS);
        $sort = $input['sort'] ?? 'name_asc';
        if ($sort === 'used_recently') {
            $lastUsed = DB::table('catalog_asana_preferences')->select('last_used_at')->whereColumn('asana_id', 'catalog_asanas.id')
                ->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId())->limit(1);
            $query->orderByDesc($lastUsed);
        } elseif ($sort === 'recent') {
            $query->orderByDesc('created_at');
        } elseif (DB::getDriverName() === 'pgsql') {
            $query->orderByRaw('normalized_name COLLATE ribka_ru '.($sort === 'name_desc' ? 'desc' : 'asc'));
        } else {
            $query->orderBy('normalized_name', $sort === 'name_desc' ? 'desc' : 'asc');
        }
        $page = $query->orderBy('id')->paginate($input['per_page'] ?? 48);
        $cards = $this->reader->serialize($page->getCollection());
        if (($input['image_style'] ?? 'original') === 'volume') {
            $displayImages = app(IllustrationStyles::class)->displayImagesForLibrary($cards);
            foreach ($cards as $id => &$card) {
                $card['display_image'] = $displayImages[$id];
            }
            unset($card);
        }

        return ['data' => array_values($cards), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(), 'facets' => $this->counts($input)];
    }

    public function counts(array $input): array
    {
        $dictionary = Category::where('workspace_id', $this->workspace->id())->whereNotNull('dimension')->orderBy('sort_order')->orderBy('id')->get();
        $groups = [];
        foreach (Facets::DIMENSIONS as $dimension) {
            $ids = $this->query($input, $dimension)->select('catalog_asanas.id');
            $counts = DB::table('catalog_asana_category')->select('category_id')->selectRaw('COUNT(DISTINCT asana_id) AS total')
                ->whereIn('asana_id', $ids)->groupBy('category_id')->pluck('total', 'category_id');
            $groups[$dimension] = $dictionary->where('dimension', $dimension)->map(fn ($value) => [
                ...$value->only(['id', 'name', 'dimension', 'system_key', 'sort_order', 'revision']), 'count' => (int) ($counts[$value->id] ?? 0),
            ])->values()->all();
        }

        return $groups;
    }
}
