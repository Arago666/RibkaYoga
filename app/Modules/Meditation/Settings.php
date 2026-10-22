<?php

namespace App\Modules\Meditation;

use Illuminate\Validation\Rule;

class Settings
{
    public static function defaults(): array
    {
        return [
            'kind' => 'meditation', 'timezone' => 'UTC', 'duration_seconds' => 600, 'preparation_seconds' => 10,
            'start_signal' => 'bowl', 'end_signal' => 'gong', 'interval_signal' => 'none', 'interval_seconds' => 0,
            'signal_volume' => 0.5, 'ambience' => 'none', 'ambience_volume' => 0.35,
            'audio_asset_id' => null, 'audio_volume' => 0.5, 'audio_repeat' => false, 'audio_end' => 'continue',
            'breathing_enabled' => false, 'breathing_signal' => 'none', 'inhale_seconds' => 4, 'exhale_seconds' => 6,
            'hold_in_seconds' => 0, 'hold_out_seconds' => 0, 'breathing_cycles' => 10,
            'tone_enabled' => false, 'tone_frequency' => 220, 'tone_volume' => 0.1, 'tone_seconds' => 600,
        ];
    }

    public static function validate(array $input): array
    {
        $rules = [
            'kind' => ['sometimes', Rule::in(['meditation', 'breathing', 'tones'])], 'timezone' => ['sometimes', 'timezone', 'max:80'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:43200'],
            'preparation_seconds' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'start_signal' => ['sometimes', Rule::in(['none', 'gong', 'bowl', 'bell'])],
            'end_signal' => ['sometimes', Rule::in(['none', 'gong', 'bowl', 'bell'])],
            'interval_signal' => ['sometimes', Rule::in(['none', 'gong', 'bowl', 'bell'])],
            'interval_seconds' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'signal_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'ambience' => ['sometimes', Rule::in(['none', 'forest', 'rain', 'waves', 'white'])],
            'ambience_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'audio_asset_id' => ['sometimes', 'nullable', 'uuid'], 'audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'audio_repeat' => ['sometimes', 'boolean'], 'audio_end' => ['sometimes', Rule::in(['continue', 'finish'])],
            'breathing_enabled' => ['sometimes', 'boolean'],
            'breathing_signal' => ['sometimes', Rule::in(['none', 'gong', 'bowl', 'bell'])],
            'inhale_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'], 'exhale_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'hold_in_seconds' => ['sometimes', 'integer', 'min:0', 'max:30'], 'hold_out_seconds' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'breathing_cycles' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'tone_enabled' => ['sometimes', 'boolean'], 'tone_frequency' => ['sometimes', 'numeric', 'min:20', 'max:2000'],
            'tone_volume' => ['sometimes', 'numeric', 'min:0', 'max:0.2'], 'tone_seconds' => ['sometimes', 'integer', 'min:1', 'max:43200'],
        ];

        return validator($input, $rules)->validate();
    }
}
