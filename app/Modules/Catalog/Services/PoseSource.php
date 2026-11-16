<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Validation\ValidationException;

class PoseSource
{
    public function validate(mixed $source): ?array
    {
        if ($source === null) {
            return null;
        }
        if (is_string($source)) {
            $this->require(strlen($source) <= 32768);
            try {
                $source = json_decode($source, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->require(false);
            }
        }
        $this->require(is_array($source) && in_array(count($source), [8, 9], true));
        $this->require(array_diff(array_keys($source), ['format', 'version', 'model', 'bones', 'position', 'camera', 'target', 'zoom', 'orientation']) === []);
        $this->require(($source['format'] ?? null) === 'ribka-pose' && ($source['version'] ?? null) === 1 && ($source['model'] ?? null) === 'yoga-human-v1');
        if (isset($source['orientation'])) {
            $this->vector($source['orientation'], 4, 1.001);
            $this->require(abs(array_sum(array_map(fn ($v) => $v * $v, $source['orientation'])) - 1) < .01);
        }
        $bones = $source['bones'] ?? null;
        $this->require(is_array($bones) && ! array_is_list($bones) && count($bones) >= 20 && count($bones) <= 80);
        foreach ($bones as $name => $rotation) {
            $this->require(is_string($name) && preg_match('/^(Hips|Spine[12]?|Neck|Head(?:Top_End)?|(?:Left|Right)(?:Shoulder|Arm|ForeArm|Hand(?:Index|Middle|Pinky|Ring|Thumb)?[1-4]?|UpLeg|Leg|Foot|ToeBase|Toe_End))$/D', $name));
            $this->vector($rotation, 4, 1.001);
            $norm = array_sum(array_map(fn ($v) => $v * $v, $rotation));
            $this->require(abs($norm - 1) < .01);
        }
        foreach (['Hips', 'Spine', 'Head', 'LeftHand', 'RightHand', 'LeftFoot', 'RightFoot'] as $bone) {
            $this->require(isset($bones[$bone]));
        }
        foreach (['position', 'camera', 'target'] as $key) {
            $this->vector($source[$key] ?? null, 3, 50);
        }
        $zoom = $source['zoom'] ?? null;
        $this->require(is_numeric($zoom) && is_finite((float) $zoom) && $zoom >= .1 && $zoom <= 20);
        $this->require(array_sum(array_map(fn ($a, $b) => ($a - $b) ** 2, $source['camera'], $source['target'])) > .0001);

        return $source;
    }

    private function vector(mixed $vector, int $length, float $limit): void
    {
        $this->require(is_array($vector) && array_is_list($vector) && count($vector) === $length);
        foreach ($vector as $value) {
            $this->require((is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= $limit);
        }
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['pose_source' => 'Некорректный исходник 3D-позы. Откройте редактор заново.']);
        }
    }
}
