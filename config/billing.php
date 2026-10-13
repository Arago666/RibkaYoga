<?php

return [
    'trial_days' => max(0, min(365, (int) env('RIBKA_TRIAL_DAYS', 14))),
    'payment_provider' => null,
    'plans' => [
        'basic' => [
            'name' => 'Базовый',
            'limits' => ['asanas' => max(0, (int) env('RIBKA_BASIC_ASANAS', 30)), 'practices' => max(0, (int) env('RIBKA_BASIC_PRACTICES', 5)), 'mesocycles' => 0],
        ],
        'pro' => ['name' => 'Про', 'limits' => ['asanas' => null, 'practices' => null, 'mesocycles' => null]],
    ],
];
