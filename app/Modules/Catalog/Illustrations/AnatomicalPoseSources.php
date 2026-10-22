<?php

namespace App\Modules\Catalog\Illustrations;

use Illuminate\Support\Str;

/** Reviewed projected anatomy is bound to both the immutable image and native geometry. */
class AnatomicalPoseSources
{
    private ?array $poses = null;

    public function __construct(private NativeVolumeSources $native) {}

    public function resolve(mixed $source, string $imageSha256): ?array
    {
        $native = $this->native->resolve($source, $imageSha256);
        if ($native === null) {
            return null;
        }
        $key = $imageSha256.':'.$native['source_drawing_sha256'];
        $pose = $this->poses()[$key] ?? null;
        if ($pose === null) {
            return null;
        }
        $this->validateReferences($pose, $native['drawing_source']);
        $native['anatomy'] = $pose;
        $native['source_pose_sha256'] = hash('sha256', json_encode($pose, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $native['render_options'] = [...$native['render_options'], 'anatomy' => $pose];

        return $native;
    }

    private function poses(): array
    {
        if ($this->poses !== null) {
            return $this->poses;
        }
        $path = resource_path('imports/illustration-styles/anatomical-poses.json');
        if (! is_file($path)) {
            return $this->poses = [];
        }
        $pack = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        abort_unless(($pack['format'] ?? null) === 'ribka-anatomical-poses' && ($pack['version'] ?? null) === 1 && is_array($pack['items'] ?? null) && array_is_list($pack['items']), 500, 'Неверный набор анатомических схем.');
        $result = [];
        foreach ($pack['items'] as $item) {
            $imageSha = $item['source_sha256'] ?? null;
            $drawingSha = $item['source_drawing_sha256'] ?? null;
            abort_unless($this->shaValid($imageSha) && $this->shaValid($drawingSha) && is_array($item['anatomy'] ?? null) && is_string($item['review_note'] ?? null), 500, 'Неверная привязка анатомической схемы.');
            $key = $imageSha.':'.$drawingSha;
            abort_if(isset($result[$key]), 500, 'Повтор анатомической схемы.');
            $pose = $item['anatomy'];
            abort_unless(($pose['version'] ?? null) === 1 && is_bool($pose['diagram'] ?? null), 500, 'Неверная версия анатомической схемы.');
            if (! $pose['diagram']) {
                abort_unless(is_array($pose['head'] ?? null) && $this->pointValid($pose['head']['center'] ?? null), 500, 'В анатомической схеме не задана голова.');
                foreach (['rx', 'ry'] as $axis) {
                    abort_unless($this->finite($pose['head'][$axis] ?? null) && $pose['head'][$axis] >= 2 && $pose['head'][$axis] <= 256, 500, 'Неверный размер головы.');
                }
                abort_unless(in_array($pose['head']['facing'] ?? null, ['left', 'right', 'front', 'back'], true) && $this->pointValid($pose['head']['down'] ?? null), 500, 'Неверное направление головы.');
                $this->validateChain($pose['torso'] ?? null);
                foreach (['arms', 'legs'] as $kind) {
                    abort_unless(is_array($pose[$kind] ?? null) && array_is_list($pose[$kind]) && count($pose[$kind]) <= 4, 500, 'Неверный набор конечностей.');
                    foreach ($pose[$kind] as $limb) {
                        $this->validateChain($limb);
                        abort_unless(is_int($limb['joint_index'] ?? null) && $limb['joint_index'] > 0 && $limb['joint_index'] < count($limb['points']) - 1, 500, 'Неверная привязка сустава.');
                        foreach (['hand', 'foot'] as $end) {
                            abort_unless(! isset($limb[$end]) || $this->pointValid($limb[$end]), 500, 'Неверная привязка кисти или стопы.');
                        }
                        foreach (['front', 'ghost', 'endpoint_is_joint'] as $flag) {
                            abort_unless(! isset($limb[$flag]) || is_bool($limb[$flag]), 500, 'Неверная видимость конечности.');
                        }
                        abort_unless(! isset($limb['depth']) || ($this->finite($limb['depth']) && abs($limb['depth']) <= 180), 500, 'Неверная глубина конечности.');
                    }
                }
            }
            $result[$key] = $pose;
        }

        return $this->poses = $result;
    }

    private function validateChain(mixed $chain): void
    {
        abort_unless(is_array($chain) && is_array($chain['points'] ?? null) && array_is_list($chain['points']) && count($chain['points']) >= 2 && count($chain['points']) <= 2000, 500, 'Неверная геометрия анатомической схемы.');
        foreach ($chain['points'] as $point) {
            abort_unless($this->pointValid($point), 500, 'Неверная координата анатомической схемы.');
        }
        abort_unless(is_array($chain['source_element_ids'] ?? null) && array_is_list($chain['source_element_ids']) && $chain['source_element_ids'] !== [], 500, 'Не задана привязка анатомической линии к исходнику.');
    }

    private function validateReferences(array $pose, array $drawing): void
    {
        $elements = array_column($drawing['elements'], null, 'id');
        $referenced = [];
        $checkIds = function (mixed $ids) use ($elements, &$referenced): void {
            abort_unless(is_array($ids) && array_is_list($ids) && count($ids) <= 1000 && count(array_unique($ids, SORT_REGULAR)) === count($ids), 500, 'Неверные ссылки анатомической схемы.');
            foreach ($ids as $id) {
                abort_unless(is_string($id) && Str::isUuid($id) && isset($elements[$id]), 500, 'Схема ссылается на другой исходник.');
                $referenced[$id] = true;
            }
        };
        foreach (['ink_element_ids', 'suppressed_element_ids', 'ring_element_ids'] as $key) {
            $checkIds($pose[$key] ?? []);
        }
        foreach ($pose['ring_element_ids'] ?? [] as $id) {
            abort_unless($elements[$id]['kind'] === 'ellipse', 500, 'Кольцо должно ссылаться на исходный эллипс.');
        }
        if (! $pose['diagram']) {
            $checkIds($pose['head']['source_element_ids'] ?? []);
            foreach ([$pose['torso'], ...$pose['arms'], ...$pose['legs']] as $chain) {
                $checkIds($chain['source_element_ids']);
                foreach ($chain['source_segments'] ?? [] as $segment) {
                    abort_unless(is_array($segment) && in_array($segment['element_id'] ?? null, $chain['source_element_ids'], true), 500, 'Неверный исходный участок анатомической линии.');
                    foreach (['from', 'to'] as $bound) {
                        abort_unless($this->finite($segment[$bound] ?? null) && $segment[$bound] >= 0 && $segment[$bound] <= 1, 500, 'Неверная граница исходного участка.');
                    }
                }
            }
        }
        abort_unless(count($referenced) === count($elements), 500, 'В анатомической схеме не разобраны все элементы исходного рисунка.');
    }

    private function shaValid(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha) === 1;
    }

    private function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function pointValid(mixed $point): bool
    {
        return is_array($point) && array_is_list($point) && count($point) === 2 && $this->finite($point[0]) && $this->finite($point[1]) && $point[0] >= -1024 && $point[0] <= 2048 && $point[1] >= -1024 && $point[1] <= 2048;
    }
}
