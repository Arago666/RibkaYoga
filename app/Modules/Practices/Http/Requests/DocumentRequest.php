<?php

namespace App\Modules\Practices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentRequest extends FormRequest
{
    use NormalizesSequenceIds;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_revision' => [$this->isMethod('PUT') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:160'],
            'theme' => ['nullable', 'string', 'max:240'],
            'note' => ['nullable', 'string', 'max:3000'],
            'practice_date' => ['nullable', 'date_format:Y-m-d'],
            'blocks' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_blocks')],
            'blocks.*.id' => ['required', 'uuid', 'distinct:strict'],
            'blocks.*.title' => ['required', 'string', 'max:160'],
            'blocks.*.purpose' => ['nullable', 'string', 'max:240'],
            'blocks.*.rows' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_rows_per_block')],
            'blocks.*.rows.*.id' => ['required', 'uuid', 'distinct:strict'],
            'blocks.*.rows.*.note' => ['nullable', 'string', 'max:1000'],
            'blocks.*.rows.*.cells' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_cells_per_row')],
            'blocks.*.rows.*.cells.*.id' => ['required', 'uuid', 'distinct:strict'],
            'blocks.*.rows.*.cells.*.card_snapshot_id' => ['present', 'nullable', 'uuid'],
            'blocks.*.rows.*.cells.*.custom_name' => ['nullable', 'string', 'max:160'],
            'blocks.*.rows.*.cells.*.group_key' => ['nullable', 'uuid'],
            'blocks.*.rows.*.cells.*.arrow_direction' => ['nullable', 'in:cross,both,up,down,left,right'],
            'blocks.*.rows.*.cells.*.note' => ['nullable', 'string', 'max:160'],
        ];
    }
}
