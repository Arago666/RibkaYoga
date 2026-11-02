<?php

namespace App\Modules\Practices\Services;

use Illuminate\Support\Facades\Validator;

/** Parameters belong to one use of a pose, never to a catalogue card. */
class ExecutionParameters
{
    public const FIELDS = ['advance_mode', 'duration_seconds', 'breath_count', 'repetitions', 'side', 'side_order', 'rest_seconds'];

    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'advance_mode' => ['sometimes', 'nullable', 'in:manual,time,breaths'],
            $prefix.'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
            $prefix.'rest_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            $prefix.'breath_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            $prefix.'repetitions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            $prefix.'side' => ['sometimes', 'nullable', 'in:left,right,both'],
            $prefix.'side_order' => ['sometimes', 'nullable', 'in:left_first,right_first'],
        ];
    }

    public static function defaultRules(): array
    {
        return [
            'default_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
            'default_rest_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    public static function defaults(array|object $document): array
    {
        $input = (array) $document;
        $values = array_intersect_key($input, self::defaultRules());
        Validator::make($values, self::defaultRules())->validate();

        return array_map(fn ($value) => $value === null ? null : (int) $value,
            array_replace(array_fill_keys(array_keys(self::defaultRules()), null), $values));
    }

    public static function resolved(array $cell, array $document): array
    {
        $values = self::values($cell);
        $defaults = self::defaults($document);
        $values['duration_seconds'] ??= $defaults['default_duration_seconds'];
        $values['rest_seconds'] ??= $defaults['default_rest_seconds'] ?? 0;
        if ($defaults['default_duration_seconds'] !== null && $values['advance_mode'] !== 'breaths') {
            $values['advance_mode'] = 'time';
        }

        return $values;
    }

    public static function values(array|object $cell): array
    {
        $input = (array) $cell;
        $values = array_intersect_key($input, array_flip(self::FIELDS));
        Validator::make($values, self::rules())->validate();
        $values = array_replace(array_fill_keys(self::FIELDS, null), $values);
        foreach (['duration_seconds', 'breath_count', 'repetitions', 'rest_seconds'] as $key) {
            $values[$key] = $values[$key] === null ? null : (int) $values[$key];
        }
        Validator::make($values, [
            'duration_seconds' => ['required_if:advance_mode,time'],
            'breath_count' => ['required_if:advance_mode,breaths'],
            'side_order' => ['required_if:side,both'],
        ])->validate();

        return $values;
    }
}
