<?php

namespace App\Modules\Sessions;

use Illuminate\Support\Facades\Validator;

class MetronomeSettings
{
    public static function rules(string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : 'required_with:'.rtrim($prefix, '.');

        return [
            $prefix.'enabled' => [$presence, 'boolean'],
            $prefix.'bpm' => [$presence, 'integer', 'min:20', 'max:300'],
            $prefix.'beats' => [$presence, 'integer', 'min:1', 'max:12'],
            $prefix.'beat_unit' => [$presence, 'integer', 'in:2,4,8,16'],
            $prefix.'pattern' => [$presence, 'array', 'list', 'min:1', 'max:12'],
            $prefix.'pattern.*' => ['required', 'in:normal,accent,strong,mute'],
            $prefix.'subdivision' => [$presence, 'integer', 'in:1,2,3,4'],
            $prefix.'sound' => [$presence, 'in:click,wood,beep,bell'],
            $prefix.'volume' => [$presence, 'integer', 'min:0', 'max:100'],
            $prefix.'count_in_bars' => [$presence, 'integer', 'in:0,1,2'],
            $prefix.'tracker' => [$presence, 'array:mode,countdown,seconds,bars,stop_at_end,reset_at_end,start_from_one'],
            $prefix.'tracker.mode' => [$presence, 'in:off,timer,bars'],
            $prefix.'tracker.countdown' => [$presence, 'boolean'],
            $prefix.'tracker.seconds' => [$presence, 'integer', 'min:1', 'max:86400'],
            $prefix.'tracker.bars' => [$presence, 'integer', 'min:1', 'max:10000'],
            $prefix.'tracker.stop_at_end' => [$presence, 'boolean'],
            $prefix.'tracker.reset_at_end' => [$presence, 'boolean'],
            $prefix.'tracker.start_from_one' => [$presence, 'boolean'],
            $prefix.'automator' => ['sometimes', 'array:enabled,step_bpm,every_bars,target_bpm'],
            $prefix.'automator.enabled' => ['required_with:'.$prefix.'automator', 'boolean'],
            $prefix.'automator.step_bpm' => ['required_with:'.$prefix.'automator', 'integer', 'min:1', 'max:30'],
            $prefix.'automator.every_bars' => ['required_with:'.$prefix.'automator', 'integer', 'min:1', 'max:1000'],
            $prefix.'automator.target_bpm' => ['required_with:'.$prefix.'automator', 'integer', 'min:20', 'max:300'],
        ];
    }

    public static function values(array $input): array
    {
        $values = Validator::make($input, self::rules())->validate();
        Validator::make($values, ['pattern' => ['size:'.$values['beats']]])->validate();
        foreach (['bpm', 'beats', 'beat_unit', 'subdivision', 'volume', 'count_in_bars'] as $field) {
            $values[$field] = (int) $values[$field];
        }
        $values['enabled'] = (bool) $values['enabled'];
        foreach (['seconds', 'bars'] as $field) {
            $values['tracker'][$field] = (int) $values['tracker'][$field];
        }
        foreach (['countdown', 'stop_at_end', 'reset_at_end', 'start_from_one'] as $field) {
            $values['tracker'][$field] = (bool) $values['tracker'][$field];
        }
        $values['automator'] = array_replace(['enabled' => false, 'step_bpm' => 1, 'every_bars' => 4, 'target_bpm' => 160], $values['automator'] ?? []);
        $values['automator']['enabled'] = (bool) $values['automator']['enabled'];
        foreach (['step_bpm', 'every_bars', 'target_bpm'] as $field) {
            $values['automator'][$field] = (int) $values['automator'][$field];
        }

        return $values;
    }
}
