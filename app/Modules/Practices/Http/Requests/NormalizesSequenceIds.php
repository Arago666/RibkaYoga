<?php

namespace App\Modules\Practices\Http\Requests;

trait NormalizesSequenceIds
{
    protected function prepareForValidation(): void
    {
        $normalize = function (mixed $value, string|int $key = '') use (&$normalize): mixed {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $value[$childKey] = $normalize($child, $childKey);
                }
            } elseif (is_string($value) && in_array($key, ['id', 'card_snapshot_id', 'group_key'], true)) {
                $value = strtolower($value);
            } elseif (is_string($value) && $key === 'custom_name') {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            return $value;
        };
        $this->replace($normalize($this->all()));
    }
}
