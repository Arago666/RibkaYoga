<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Exact-image editorial descriptions; no pose, classification or medical fields are inferred. */
class ReferenceEnrichment
{
    public function __construct(private WorkspaceContext $workspace) {}

    public function run(array $payload, bool $apply = false): array
    {
        $access = $this->workspace->details();
        abort_unless($access['role'] === 'owner', 403);
        $data = Validator::make($payload, [
            'format' => ['required', 'in:ribka-reference-v1'],
            'workspace_id' => ['required', 'in:'.$access['id']],
            'items' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'items.*' => ['required', 'array:asana_id,expected_revision,expected_image_asset_id,teacher_cues,steps,editorial,requires_method_review'],
            'items.*.asana_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'items.*.expected_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'items.*.expected_image_asset_id' => ['required', 'uuid'],
            'items.*.teacher_cues' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'items.*.teacher_cues.*' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'items.*.steps' => ['present', 'array', 'list', 'max:50'],
            'items.*.steps.*' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'items.*.editorial' => ['required', 'array:author,source,reviewed,review_note'],
            'items.*.editorial.author' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'items.*.editorial.source' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'items.*.editorial.reviewed' => ['present', 'boolean'],
            'items.*.editorial.review_note' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'items.*.requires_method_review' => ['required', 'boolean'],
        ])->validate();
        $items = $data['items'];
        foreach ($items as &$item) {
            if ($item['editorial']['reviewed'] !== false) {
                throw ValidationException::withMessages(['editorial.reviewed' => 'Визуальная справка не может объявляться проверенной методикой.']);
            }
            $item['asana_id'] = strtolower($item['asana_id']);
            $item['expected_image_asset_id'] = strtolower($item['expected_image_asset_id']);
            $item['expected_revision'] = (int) $item['expected_revision'];
            $item['teacher_cues'] = array_map('trim', $item['teacher_cues']);
            $item['steps'] = array_map('trim', $item['steps']);
            foreach (['author', 'source', 'review_note'] as $field) {
                $item['editorial'][$field] = trim($item['editorial'][$field]);
            }
        }
        unset($item);
        usort($items, fn ($a, $b) => strcmp($a['asana_id'], $b['asana_id']));

        return DB::transaction(function () use ($items, $apply, $access) {
            $cards = Asana::where('workspace_id', $access['id'])->whereIn('id', array_column($items, 'asana_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($cards->count() === count($items), 404, 'Одна из карточек не принадлежит указанному пространству.');
            $plan = [];
            foreach ($items as $item) {
                $card = $cards[$item['asana_id']];
                abort_if($card->archived_at !== null, 409, 'Справка архивной карточки не изменяется.');
                abort_unless($card->current_image_asset_id === $item['expected_image_asset_id'], 409, 'Изображение изменилось после визуальной сверки.');
                $editorial = $this->mergedEditorial($card->editorial ?? [], $item['editorial']);
                $same = ($card->teacher_cues ?? []) === $item['teacher_cues'] && ($card->steps ?? []) === $item['steps'] && $this->sameEditorial($card->editorial ?? [], $editorial);
                $replay = $card->revision === $item['expected_revision'] + 1 && $same;
                if (! $replay) {
                    abort_unless($card->revision === $item['expected_revision'], 409, 'Карточка изменилась после подготовки справки.');
                    abort_unless(empty($card->steps) && empty($card->teacher_cues), 409, 'Авторские шаги или подсказки уже заполнены; автоматическая замена запрещена.');
                    foreach (['author', 'source'] as $field) {
                        $existing = $card->editorial[$field] ?? null;
                        abort_if($existing !== null && trim((string) $existing) !== '' && $existing !== $item['editorial'][$field], 409, 'У карточки уже есть другая авторская справка.');
                    }
                    abort_if(($card->editorial['reviewed'] ?? false) === true, 409, 'Проверенная автором справка не заменяется визуальным описанием.');
                }
                $plan[] = ['card' => $card, 'item' => $item, 'editorial' => $editorial, 'replay' => $replay];
            }
            $results = [];
            foreach ($plan as $entry) {
                ['card' => $card, 'item' => $item, 'editorial' => $editorial, 'replay' => $replay] = $entry;
                if ($apply && ! $replay) {
                    $card->forceFill(['teacher_cues' => $item['teacher_cues'], 'steps' => $item['steps'], 'editorial' => $editorial, 'revision' => $card->revision + 1, 'updated_by' => $this->workspace->userId()])->save();
                }
                $results[] = ['asana_id' => $card->id, 'expected_revision' => $item['expected_revision'], 'revision' => $card->revision, 'status' => $replay ? 'already_applied' : ($apply ? 'applied' : 'would_apply'), 'teacher_cues' => count($item['teacher_cues']), 'steps' => count($item['steps']), 'requires_method_review' => (bool) $item['requires_method_review']];
            }

            return ['applied' => $apply, 'changed_count' => count(array_filter($plan, fn ($entry) => ! $entry['replay'])), 'already_applied_count' => count(array_filter($plan, fn ($entry) => $entry['replay'])), 'requires_method_review_count' => count(array_filter($items, fn ($item) => $item['requires_method_review'])), 'items' => $results];
        });
    }

    private function mergedEditorial(array $existing, array $incoming): array
    {
        $before = trim((string) ($existing['review_note'] ?? ''));
        $note = $incoming['review_note'];
        $merged = $before === '' ? $note : ($before === $note || str_ends_with($before, "\n".$note) ? $before : $before."\n".$note);
        abort_if(mb_strlen($merged) > 2000, 422, 'Объединённая редакционная заметка длиннее 2000 символов.');

        return array_replace($existing, $incoming, ['review_note' => $merged]);
    }

    private function sameEditorial(array $before, array $after): bool
    {
        ksort($before, SORT_STRING);
        ksort($after, SORT_STRING);

        return $before === $after;
    }
}
