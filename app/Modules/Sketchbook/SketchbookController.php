<?php

namespace App\Modules\Sketchbook;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\PublicApi\DrawingSources;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\NativeImages;
use App\Modules\Sketchbook\PublicApi\SketchImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SketchbookController
{
    public function __construct(private WorkspaceContext $workspace, private DrawingSources $sources, private AssetStore $assets, private NativeImages $native) {}

    private function owned()
    {
        return DB::table('sketchbook_pages')->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId());
    }

    private function data(object $row, bool $source = false): array
    {
        $data = (array) $row;
        unset($data['drawing_source']);
        if ($source) {
            $data['drawing_source'] = $row->drawing_source ? json_decode($row->drawing_source, true) : null;
        }
        $data['image_url'] = $row->image_asset_id ? $this->assets->get($row->image_asset_id)['image_url'] : null;

        return $data;
    }

    public function index(Request $request)
    {
        $input = $request->validate(['q' => 'nullable|string|max:160', 'archive' => ['nullable', Rule::in(['active', 'archived'])], 'page' => 'nullable|integer|min:1']);
        $query = $this->owned();
        ($input['archive'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if ($input['q'] ?? '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $input['q']).'%');
        }
        $rows = $query->orderByDesc('updated_at')->paginate(24);

        return response()->json(['data' => $rows->map(fn ($row) => $this->data($row)), 'total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]);
    }

    public function store(Request $request)
    {
        $input = $request->validate(['title' => 'required|string|max:160', 'page_format' => ['nullable', Rule::in(['square', 'a4-portrait', 'a4-landscape'])]]);
        $id = (string) Str::uuid();
        DB::table('sketchbook_pages')->insert(['id' => $id, 'workspace_id' => $this->workspace->id(), 'user_id' => $this->workspace->userId(), 'title' => trim($input['title']), 'page_format' => $input['page_format'] ?? 'square', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $this->show($id)->setStatusCode(201);
    }

    public function show(string $id)
    {
        $row = $this->owned()->where('id', $id)->first();
        abort_unless($row, 404);

        return response()->json(['data' => $this->data($row, true)]);
    }

    public function update(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'title' => 'required|string|max:160', 'page_format' => ['required', Rule::in(['square', 'a4-portrait', 'a4-landscape'])], 'drawing_source' => 'sometimes', 'image' => 'nullable|file']);
        $source = $request->exists('drawing_source') ? $this->sources->validate($input['drawing_source'], true) : null;
        abort_if($source && ! $request->hasFile('image'), 422, 'Примените рисунок перед сохранением листа.');
        $asset = null;
        try {
            $result = DB::transaction(function () use ($input, $source, $request, $id, &$asset) {
                $row = $this->owned()->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_unless((int) $row->revision === (int) $input['expected_revision'], 409, 'Лист изменён в другом окне. Сохраните свою копию.');
                abort_if($row->archived_at, 422, 'Сначала восстановите лист.');
                $values = ['title' => trim($input['title']), 'page_format' => $input['page_format'], 'revision' => $row->revision + 1, 'updated_at' => now()];
                if ($source) {
                    $asset = $this->assets->store($request->file('image'));
                    abort_unless($asset['mime'] === 'image/png' && $asset['width'] === 1024 && $asset['height'] === 1024, 422, 'Нужен PNG 1024 × 1024.');
                    $this->native->remember($asset['id'], $source, true);
                    app(SketchImages::class)->remember($asset['id']);
                    $values += ['image_asset_id' => $asset['id'], 'drawing_source' => json_encode($source, JSON_THROW_ON_ERROR)];
                }
                $this->owned()->where('id', $id)->update($values);

                return $this->data($this->owned()->where('id', $id)->first(), true);
            });
        } catch (\Throwable $failure) {
            if ($asset) {
                $this->assets->discardUnattached($asset['id']);
            }
            throw $failure;
        }

        return response()->json(['data' => $result]);
    }

    public function copy(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1']);
        $newId = DB::transaction(function () use ($id, $input) {
            $row = $this->owned()->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $input['expected_revision'], 409);
            $data = (array) $row;
            $data['id'] = (string) Str::uuid();
            $data['title'] = mb_substr($row->title, 0, 150).' — копия';
            $data['revision'] = 1;
            $data['archived_at'] = null;
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('sketchbook_pages')->insert($data);

            return $data['id'];
        });

        return $this->show($newId)->setStatusCode(201);
    }

    public function archive(Request $request, string $id)
    {
        $input = $request->validate(['expected_revision' => 'required|integer|min:1', 'restore' => 'sometimes|boolean']);
        abort_unless($this->owned()->where('id', $id)->where('revision', $input['expected_revision'])->update(['archived_at' => ($input['restore'] ?? false) ? null : now(), 'revision' => $input['expected_revision'] + 1, 'updated_at' => now()]), 409, 'Лист изменился. Обновите список.');

        return $this->show($id);
    }
}
