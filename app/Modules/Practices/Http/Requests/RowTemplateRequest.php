<?php

namespace App\Modules\Practices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RowTemplateRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:1000'],
            'cells' => ['required', 'array', 'list', 'min:1', 'max:'.config('sequence.max_cells_per_row')],
            'cells.*.id' => ['required', 'uuid', 'distinct:strict'],
            'cells.*.card_snapshot_id' => ['present', 'nullable', 'uuid'],
            'cells.*.custom_name' => ['nullable', 'string', 'max:160'],
            'cells.*.group_key' => ['nullable', 'uuid'],
            'cells.*.note' => ['nullable', 'string', 'max:160'],
        ];
    }
}
