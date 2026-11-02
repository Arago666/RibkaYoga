<?php

namespace App\Modules\Practices\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewAnnotations
{
    public static function empty(): array
    {
        return ['comments' => [], 'groups' => [], 'arrows' => []];
    }

    public static function values(array $input, array $document): array
    {
        $values = Validator::make(['annotations' => $input], [
            'annotations' => ['required', 'array:comments,groups,arrows'],
            'annotations.comments' => ['present', 'array', 'list', 'max:1000'],
            'annotations.comments.*' => ['array:id,cell_id,body,color'],
            'annotations.comments.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'annotations.comments.*.cell_id' => ['required', 'uuid'],
            'annotations.comments.*.body' => ['required', 'string', 'max:3000'],
            'annotations.comments.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'annotations.groups' => ['present', 'array', 'list', 'max:200'],
            'annotations.groups.*' => ['array:id,cell_ids,body,color'],
            'annotations.groups.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'annotations.groups.*.cell_ids' => ['required', 'array', 'list', 'min:2', 'max:2000'],
            'annotations.groups.*.cell_ids.*' => ['required', 'uuid'],
            'annotations.groups.*.body' => ['present', 'nullable', 'string', 'max:3000'],
            'annotations.groups.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'annotations.arrows' => ['present', 'array', 'list', 'max:500'],
            'annotations.arrows.*' => ['array:id,from_cell_id,to_cell_id,body,color'],
            'annotations.arrows.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'annotations.arrows.*.from_cell_id' => ['required', 'uuid'],
            'annotations.arrows.*.to_cell_id' => ['required', 'uuid'],
            'annotations.arrows.*.body' => ['sometimes', 'nullable', 'string', 'max:500'],
            'annotations.arrows.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ])->validate()['annotations'];
        $cells = [];
        foreach ($document['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['card'] || trim($cell['custom_name'] ?? '') !== '') {
                        $cells[strtolower($cell['id'])] = true;
                    }
                }
            }
        }
        $ids = [];
        foreach ($values as $kind => &$entries) {
            foreach ($entries as &$entry) {
                $entry['id'] = strtolower($entry['id']);
                if (isset($ids[$entry['id']])) {
                    throw ValidationException::withMessages(['annotations' => 'Каждая пометка должна иметь собственный идентификатор.']);
                }
                $ids[$entry['id']] = true;
                $entry['body'] = trim($entry['body'] ?? '');
                $entry['color'] = strtolower($entry['color']);
                $targets = match ($kind) {
                    'comments' => [$entry['cell_id']],
                    'groups' => $entry['cell_ids'],
                    'arrows' => [$entry['from_cell_id'], $entry['to_cell_id']],
                };
                $targets = array_map('strtolower', $targets);
                if (count(array_unique($targets)) !== count($targets) || array_diff($targets, array_keys($cells))) {
                    throw ValidationException::withMessages(['annotations' => 'Пометка должна ссылаться на разные заполненные ячейки этого разбора.']);
                }
                if ($kind === 'comments') {
                    if ($entry['body'] === '') {
                        throw ValidationException::withMessages(['annotations' => 'Введите текст комментария.']);
                    }
                    $entry['cell_id'] = $targets[0];
                } elseif ($kind === 'groups') {
                    $entry['cell_ids'] = $targets;
                } else {
                    [$entry['from_cell_id'], $entry['to_cell_id']] = $targets;
                }
            }
            unset($entry);
        }
        unset($entries);

        return $values;
    }
}
