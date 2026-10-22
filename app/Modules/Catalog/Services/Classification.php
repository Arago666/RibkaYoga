<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Classification
{
    public function __construct(private WorkspaceContext $workspace, private Facets $facets) {}

    public function run(array $payload, bool $apply): array
    {
        $data = Validator::make($payload, [
            'format' => ['required', 'in:ribka-classification-v1'], 'workspace_id' => ['required', 'in:'.$this->workspace->id()],
            'mode' => ['sometimes', Rule::in(['replace', 'append'])],
            'items' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'items.*' => ['array:asana_id,expected_revision,expected_image_asset_id,facet_assignments,manual_verified,classification_status,review_note'],
            'items.*.asana_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'items.*.expected_revision' => ['required', 'integer', 'min:1'],
            'items.*.expected_image_asset_id' => ['sometimes', 'uuid'],
            'items.*.review_note' => ['sometimes', 'string', 'max:2000'],
            'items.*.manual_verified' => ['required', 'accepted'],
            'items.*.classification_status' => ['sometimes', Rule::in(['partial', 'reviewed'])],
            'items.*.facet_assignments' => ['required', 'array'],
        ])->validate();
        $items = $data['items'];
        usort($items, fn ($a, $b) => strcmp($a['asana_id'], $b['asana_id']));

        return DB::transaction(function () use ($items, $data, $apply) {
            $results = [];
            foreach ($items as $item) {
                $card = Asana::where('workspace_id', $this->workspace->id())->lockForUpdate()->findOrFail($item['asana_id']);
                abort_unless($card->revision === $item['expected_revision'], 409, 'Карточка изменилась после подготовки разметки: '.$card->name);
                if (isset($item['expected_image_asset_id'])) {
                    abort_unless($card->current_image_asset_id === $item['expected_image_asset_id'], 409, 'Изображение изменилось после визуальной проверки: '.$card->name);
                }
                if ($card->archived_at) {
                    throw ValidationException::withMessages(['items' => 'Разметка архива не изменяется: '.$card->name]);
                }
                $assignments = $item['facet_assignments'];
                // CLI manifests may use stable dictionary keys; the HTTP contract uses UUIDs.
                foreach ($assignments as $dimension => &$ids) {
                    if (! is_array($ids)) {
                        throw ValidationException::withMessages(['facet_assignments' => 'Ожидается список признаков.']);
                    }
                    $ids = array_map(function ($value) {
                        if (! is_string($value)) {
                            return $value;
                        }

                        return str_contains($value, '.')
                            ? (Category::where('workspace_id', $this->workspace->id())->where('system_key', $value)->value('id') ?? $value)
                            : $value;
                    }, $ids);
                }
                unset($ids);
                $assignments = $this->facets->validate($assignments, $this->workspace->id(), true);
                $before = collect(Facets::DIMENSIONS)->mapWithKeys(fn ($dimension) => [$dimension => $card->categories->where('dimension', $dimension)->pluck('id')->values()->all()])->all();
                $after = $before;
                foreach ($assignments as $dimension => $ids) {
                    $after[$dimension] = ($data['mode'] ?? 'replace') === 'append' && $dimension !== 'difficulty'
                        ? array_values(array_unique([...$before[$dimension], ...$ids])) : $ids;
                }
                $status = $item['classification_status'] ?? 'partial';
                if ($status === 'reviewed' && count(array_filter($after)) !== count(Facets::DIMENSIONS)) {
                    throw ValidationException::withMessages(['classification_status' => 'Для полной разметки заполните четыре группы. Неизвестные признаки оставьте с частичной разметкой.']);
                }
                $changed = $before !== $after || $card->classification_status !== $status;
                if ($apply && $changed) {
                    $this->facets->sync($card, $after);
                    if (! empty($item['review_note'])) {
                        $editorial = $card->editorial ?? [];
                        $editorial['review_note'] = trim(($editorial['review_note'] ?? '')."\n".$item['review_note']);
                        $card->editorial = $editorial;
                    }
                    $card->forceFill(['classification_status' => $status, 'revision' => $card->revision + 1, 'updated_by' => $this->workspace->userId()])->save();
                }
                $results[] = ['id' => $card->id, 'name' => $card->name, 'expected_revision' => $item['expected_revision'],
                    'revision' => $card->revision, 'before' => $before, 'after' => $after, 'classification_status' => $status, 'changed' => $changed];
            }

            return ['applied' => $apply, 'changed_count' => count(array_filter($results, fn ($item) => $item['changed'])), 'items' => $results];
        });
    }
}
