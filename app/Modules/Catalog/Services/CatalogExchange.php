<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Collections\PublicApi\CollectionMembership;
use App\Modules\Media\PublicApi\AssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CatalogExchange
{
    public function __construct(private WorkspaceContext $workspace, private CatalogReader $reader, private AssetStore $assets, private CollectionMembership $collections) {}

    public function export(array $ids): array
    {
        Validator::make(['ids' => $ids], ['ids' => ['array', 'list', 'max:100'], 'ids.*' => ['uuid', 'distinct']])->validate();
        $query = Asana::query()->select(CatalogReader::SUMMARY_COLUMNS)->where('workspace_id', $this->workspace->id());
        if ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $query->whereNull('archived_at')->limit(100);
        }
        $records = $query->orderBy('id')->get();
        if ($ids && $records->count() !== count($ids)) {
            abort(404);
        }
        $cards = $this->reader->serialize($records);
        $images = [];
        $bytes = 0;
        foreach ($cards as &$card) {
            $key = $card['image_asset_id'];
            $images[$key] ??= $this->assets->originalData($key);
            $bytes += $images[$key]['bytes'];
            if ($bytes > 32 * 1024 * 1024) {
                throw ValidationException::withMessages(['ids' => 'Выберите меньше карточек: экспорт ограничен 32 МБ изображений.']);
            }
            $card['expected_revision'] = $card['revision'];
            $card['source_image'] = $images[$key]['data_url'];
            unset($card['image_url'], $card['thumbnail_url']);
        }
        unset($card);

        return [
            'format' => 'ribka-codex-job-v1', 'exported_at' => now()->toISOString(),
            'workspace_id' => $this->workspace->id(),
            'task' => 'Подготовьте аккуратные изображения асан на белом фоне, сохраняя положение тела и смысл исходника. При неоднозначном рисунке уточните у владельца. Не меняйте id и expected_revision. Подготовьте results.json формата ribka-codex-results-v1: items[{id, expected_revision, image_data: data:image/png;base64,...}] либо image_file с относительным путём для CLI-импорта. Изображения сначала показать владельцу.',
            'cards' => array_values($cards),
            'result_example' => ['format' => 'ribka-codex-results-v1', 'workspace_id' => $this->workspace->id(), 'items' => []],
        ];
    }

    public function import(array $payload): array
    {
        Validator::make($payload, [
            'format' => ['required', 'in:ribka-codex-results-v1'],
            'workspace_id' => ['required', 'in:'.$this->workspace->id()],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'items.*.expected_revision' => ['required', 'integer', 'min:1'],
            'items.*.image_data' => ['required', 'string', 'max:14000000'],
        ])->validate();
        $pending = [];
        try {
            foreach ($payload['items'] as $item) {
                if (! preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#D', $item['image_data'], $match)) {
                    throw ValidationException::withMessages(['items' => 'Ожидается PNG, JPEG или WebP в image_data.']);
                }
                $contents = base64_decode($match[2], true);
                if ($contents === false || strlen($contents) > 10 * 1024 * 1024) {
                    throw ValidationException::withMessages(['items' => 'Некорректное изображение или превышен размер 10 МБ.']);
                }
                $this->find($item['id']);
                $temp = tmpfile();
                if (! $temp) {
                    throw new \RuntimeException('Не удалось создать временный файл.');
                }
                try {
                    fwrite($temp, $contents);
                    $file = new UploadedFile(stream_get_meta_data($temp)['uri'], 'codex-'.$item['id'].'.'.$match[1], null, null, true);
                    $pending[$item['id']] = $this->assets->store($file);
                } finally {
                    fclose($temp);
                }
            }
            $result = DB::transaction(function () use ($payload, $pending) {
                $this->collections->lockWorkspace();
                $items = $payload['items'];
                usort($items, fn ($a, $b) => strcmp($a['id'], $b['id']));
                $saved = [];
                foreach ($items as $item) {
                    $asana = $this->find($item['id'], true);
                    abort_unless($asana->revision === (int) $item['expected_revision'], 409, 'Карточка изменена после экспорта. Экспортируйте новое задание.');
                    $asana->forceFill(['current_image_asset_id' => $pending[$item['id']]['id'], 'drawing_source' => null, 'revision' => $asana->revision + 1, 'updated_by' => $this->workspace->userId()])->save();
                    $saved[] = $asana->id;
                }

                return $saved;
            });

            return ['updated_ids' => $result, 'message' => 'Картинки обновлены в библиотеке. Готовые практики можно обновить отдельно в редакторе.'];
        } catch (\Throwable $error) {
            foreach ($pending as $asset) {
                $this->assets->discardUnattached($asset['id']);
            }
            throw $error;
        }
    }

    private function find(string $id, bool $lock = false): Asana
    {
        $query = Asana::where('workspace_id', $this->workspace->id());

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }
}
