<?php

namespace App\Modules\Catalog\Services;

class Names
{
    public static function normalize(string $value): string
    {
        $value = str_replace('ё', 'е', mb_strtolower(trim($value)));
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D);
        } else {
            $value = strtr($value, ['ā' => 'a', 'ī' => 'i', 'ū' => 'u', 'ṛ' => 'r', 'ṝ' => 'r', 'ḷ' => 'l', 'ṅ' => 'n', 'ñ' => 'n', 'ṭ' => 't', 'ḍ' => 'd', 'ṇ' => 'n', 'ś' => 's', 'ṣ' => 's', 'ṃ' => 'm', 'ṁ' => 'm', 'ḥ' => 'h']);
        }
        $value = preg_replace('/(?<=\p{Latin})\p{Mn}+/u', '', $value);

        return preg_replace('/\s+/u', ' ', class_exists(\Normalizer::class) ? \Normalizer::normalize($value, \Normalizer::FORM_C) : $value);
    }

    public static function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::normalize($value)).'%';
    }
}
