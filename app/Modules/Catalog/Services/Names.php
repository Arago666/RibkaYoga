<?php

namespace App\Modules\Catalog\Services;

class Names
{
    public static function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower(trim($value))));
    }

    public static function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::normalize($value)).'%';
    }
}
