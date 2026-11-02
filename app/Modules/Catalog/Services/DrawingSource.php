<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Editable source stays in the catalog; saved practice images remain immutable. */
class DrawingSource
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    public function validate(mixed $value, bool $allowEmpty = false): ?array
    {
        if (is_string($value)) {
            $this->require(strlen($value) <= self::MAX_BYTES, 'Исходник рисунка должен быть не больше 4 МБ.');
            try {
                // Preserve the distinction between JSON objects and lists, even when empty.
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->require(false, 'Не удалось прочитать исходник рисунка.');
            }
        } elseif (is_array($value)) {
            try {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $this->require(strlen($encoded) <= self::MAX_BYTES, 'Исходник рисунка должен быть не больше 4 МБ.');
                $value = json_decode($encoded, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->require(false, 'Не удалось прочитать исходник рисунка.');
            }
        }
        if ($value === null) {
            return null;
        }
        $this->object($value, ['format', 'version', 'width', 'height', 'smoothing', 'elements'], ($value->version ?? null) === 2 ? ['paper'] : []);
        $this->require($value->format === 'ribka-drawing' && in_array($value->version, [1, 2], true) && $value->width === 1024 && $value->height === 1024);
        $this->number($value->smoothing, 0, 100);
        $this->require(is_array($value->elements) && array_is_list($value->elements) && count($value->elements) >= ($allowEmpty ? 0 : 1) && count($value->elements) <= 1000);
        if (property_exists($value, 'paper')) {
            $this->object($value->paper, ['style', 'opacity', 'print']);
            $this->require(in_array($value->paper->style, ['blank', 'ruled', 'grid'], true) && is_bool($value->paper->print));
            $this->number($value->paper->opacity, 0, 1);
        }
        $this->require(count(array_filter($value->elements, fn ($element) => ($element->kind ?? null) === 'image')) <= 12, 'На одном листе можно разместить до 12 фотографий.');
        $ids = [];
        $totalPoints = 0;
        $groups = [];
        foreach ($value->elements as $element) {
            $this->object($element, ['id', 'kind', 'width', 'rotation', 'points'], $value->version === 2 ? ['color', 'group_id', 'opacity', ...(($element->kind ?? null) === 'image' ? ['image_data', 'flip_x'] : [])] : []);
            if (property_exists($element, 'opacity')) {
                $this->number($element->opacity, 0, 1);
            }
            if (($element->kind ?? null) === 'image') {
                if (property_exists($element, 'flip_x')) {
                    $this->require(is_bool($element->flip_x));
                }
                $this->require(property_exists($element, 'image_data') && is_string($element->image_data) && strlen($element->image_data) <= 2 * 1024 * 1024);
                $this->require(preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/=]+)$/D', $element->image_data, $matches) === 1);
                $binary = base64_decode($matches[2], true);
                $this->require(is_string($binary) && $binary !== '');
                $size = @getimagesizefromstring($binary);
                $this->require(is_array($size) && $size[0] >= 1 && $size[0] <= 1024 && $size[1] >= 1 && $size[1] <= 1024 && ($size['mime'] ?? null) === 'image/'.$matches[1]);
                $this->require(function_exists('imagecreatefromstring'), 'Для фотографий в рисунке требуется расширение GD.');
                $decoded = @imagecreatefromstring($binary);
                $this->require($decoded !== false, 'Не удалось прочитать фотографию в рисунке.');
                imagedestroy($decoded);
            }
            if (property_exists($element, 'color')) {
                $this->require(is_string($element->color) && preg_match('/^#[0-9a-f]{6}$/iD', $element->color) === 1);
                $element->color = strtolower($element->color);
            }
            if (property_exists($element, 'group_id')) {
                $this->require(is_string($element->group_id) && Str::isUuid($element->group_id));
                $element->group_id = strtolower($element->group_id);
                $groups[$element->group_id] = ($groups[$element->group_id] ?? 0) + 1;
            }
            $this->require(is_string($element->id) && Str::isUuid($element->id));
            $id = strtolower($element->id);
            $this->require(! isset($ids[$id]));
            $ids[$id] = true;
            $element->id = $id;
            $this->require(in_array($element->kind, ['pen', 'eraser', 'line', 'curve', 'ellipse', 'figure', ...($value->version === 2 ? ['image'] : [])], true));
            $this->number($element->width, 1, 100);
            $this->number($element->rotation, -360, 360);
            $this->require(is_array($element->points) && array_is_list($element->points));
            $count = count($element->points);
            $this->require(match ($element->kind) {
                'pen', 'eraser' => $count >= 1 && $count <= 3000,
                'line', 'ellipse', 'image' => $count === 2,
                'curve' => $count === 3,
                'figure' => $count === 15,
            });
            $totalPoints += $count;
            $this->require($totalPoints <= 50000);
            foreach ($element->points as $point) {
                $this->object($point, ['x', 'y']);
                $this->number($point->x, 0, 1024);
                $this->number($point->y, 0, 1024);
            }
        }

        $this->require(! array_filter($groups, fn ($count) => $count < 2));

        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 32, JSON_THROW_ON_ERROR);
    }

    private function object(mixed $value, array $keys, array $optional = []): void
    {
        $this->require($value instanceof \stdClass);
        $actual = array_keys(get_object_vars($value));
        sort($actual);
        sort($keys);
        $this->require(! array_diff($keys, $actual) && ! array_diff($actual, [...$keys, ...$optional]));
    }

    private function number(mixed $value, float $minimum, float $maximum): void
    {
        $this->require((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $minimum && $value <= $maximum);
    }

    private function require(bool $condition, string $message = 'Исходник рисунка имеет неверный формат или превышает допустимую сложность.'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['drawing_source' => $message]);
        }
    }
}
