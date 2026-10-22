<?php

namespace App\Modules\Catalog\Illustrations;

use Illuminate\Support\Str;

/** Original solid 3D geometry, orthographic camera and depth-sorted lit mesh. */
class MannequinRenderer
{
    public const BONES = [
        ['head', 'neck'],
        ['neck', 'pelvis'], ['leftShoulder', 'rightShoulder'], ['leftHip', 'rightHip'],
        ['leftShoulder', 'leftElbow'], ['leftElbow', 'leftWrist'],
        ['rightShoulder', 'rightElbow'], ['rightElbow', 'rightWrist'],
        ['leftHip', 'leftKnee'], ['leftKnee', 'leftAnkle'], ['leftAnkle', 'leftToe'],
        ['rightHip', 'rightKnee'], ['rightKnee', 'rightAnkle'], ['rightAnkle', 'rightToe'],
    ];

    public function render(array $pose, string $style, string $character): array
    {
        abort_unless(function_exists('imagecreatetruecolor'), 503, 'Для подготовки вариантов изображения нужно включить PHP GD.');
        $joints = $this->character($pose['joints'], $character);
        $yaw = deg2rad($pose['camera']['yaw']);
        $pitch = deg2rad($pose['camera']['pitch']);
        $projection = $this->projection($joints, $yaw, $pitch);
        $image = imagecreatetruecolor(1024, 1024);
        imageantialias($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $drawing = null;
        if ($style === 'line') {
            $drawing = $this->line($image, $joints, $projection);
        } else {
            $this->solid($image, $joints, $pose, $character, $projection);
        }
        ob_start();
        imagepng($image, null, 7);
        $png = ob_get_clean();
        imagedestroy($image);
        abort_unless(is_string($png) && strlen($png) > 0, 500, 'Не удалось сформировать изображение.');

        return ['png' => $png, 'drawing_source' => $drawing];
    }

    public function character(array $joints, string $character): array
    {
        $shoulders = $character === 'male' ? 1.1 : ($character === 'female' ? 0.94 : 1);
        $hips = $character === 'female' ? 1.12 : ($character === 'male' ? 0.94 : 1);
        foreach ([['leftShoulder', 'rightShoulder', $shoulders], ['leftHip', 'rightHip', $hips]] as [$left, $right, $factor]) {
            $center = $this->mul($this->add($joints[$left], $joints[$right]), 0.5);
            foreach ([$left, $right] as $key) {
                $joints[$key] = $this->add($center, $this->mul($this->sub($joints[$key], $center), $factor));
            }
        }

        return $joints;
    }

    private function projection(array $joints, float $yaw, float $pitch): \Closure
    {
        $rotated = array_map(fn ($p) => $this->rotate($p, $yaw, $pitch), $joints);
        $xs = array_column($rotated, 0);
        $ys = array_column($rotated, 1);
        $minX = min($xs) - 0.25;
        $maxX = max($xs) + 0.25;
        $minY = min($ys) - 0.25;
        $maxY = max($ys) + 0.25;
        $scale = 840 / max($maxX - $minX, $maxY - $minY);
        $cx = ($minX + $maxX) / 2;
        $cy = ($minY + $maxY) / 2;

        return function (array $point) use ($yaw, $pitch, $scale, $cx, $cy): array {
            [$x, $y, $z] = $this->rotate($point, $yaw, $pitch);

            return [512 + ($x - $cx) * $scale, 512 - ($y - $cy) * $scale, $z, $scale];
        };
    }

    private function line(\GdImage $image, array $joints, \Closure $project): array
    {
        $black = imagecolorallocate($image, 18, 22, 18);
        imagesetthickness($image, 1);
        $elements = [];
        foreach (self::BONES as [$a, $b]) {
            $start = $project($joints[$a]);
            $end = $project($joints[$b]);
            $dx = $end[0] - $start[0];
            $dy = $end[1] - $start[1];
            $length = max(0.001, hypot($dx, $dy));
            $nx = -$dy / $length * 5;
            $ny = $dx / $length * 5;
            imagefilledpolygon($image, [(int) ($start[0] + $nx), (int) ($start[1] + $ny), (int) ($end[0] + $nx), (int) ($end[1] + $ny), (int) ($end[0] - $nx), (int) ($end[1] - $ny), (int) ($start[0] - $nx), (int) ($start[1] - $ny)], $black);
            imagefilledellipse($image, (int) $start[0], (int) $start[1], 10, 10, $black);
            imagefilledellipse($image, (int) $end[0], (int) $end[1], 10, 10, $black);
            $elements[] = ['id' => (string) Str::uuid(), 'kind' => 'line', 'width' => 10, 'rotation' => 0, 'points' => [['x' => round($start[0], 3), 'y' => round($start[1], 3)], ['x' => round($end[0], 3), 'y' => round($end[1], 3)]]];
        }
        $head = $project($joints['head']);
        $radius = $head[3] * 0.165;
        imagefilledellipse($image, (int) $head[0], (int) $head[1], (int) ($radius * 2 + 10), (int) ($radius * 2 + 10), $black);
        imagefilledellipse($image, (int) $head[0], (int) $head[1], (int) ($radius * 2 - 10), (int) ($radius * 2 - 10), imagecolorallocate($image, 255, 255, 255));
        $elements[] = ['id' => (string) Str::uuid(), 'kind' => 'ellipse', 'width' => 10, 'rotation' => 0, 'points' => [['x' => round($head[0] - $radius, 3), 'y' => round($head[1] - $radius, 3)], ['x' => round($head[0] + $radius, 3), 'y' => round($head[1] + $radius, 3)]]];

        return ['format' => 'ribka-drawing', 'version' => 1, 'width' => 1024, 'height' => 1024, 'smoothing' => 70, 'elements' => $elements];
    }

    private function solid(\GdImage $image, array $joints, array $pose, string $character, \Closure $project): void
    {
        $triangles = [];
        $limbRadius = $character === 'male' ? 0.067 : ($character === 'female' ? 0.054 : 0.06);
        $bodyColor = $character === 'female' ? [117, 151, 125] : ($character === 'male' ? [112, 137, 159] : [141, 149, 144]);
        $jointColor = [188, 182, 163];
        $this->cylinder($triangles, $joints['leftShoulder'], $joints['rightShoulder'], 0.09, $bodyColor);
        foreach (self::BONES as [$a, $b]) {
            if (in_array($a.':'.$b, ['neck:pelvis', 'leftShoulder:rightShoulder', 'leftHip:rightHip'], true)) {
                continue;
            }
            $radius = str_contains($a, 'Hip') || str_contains($a, 'Knee') ? $limbRadius * 1.2 : $limbRadius;
            $this->cylinder($triangles, $joints[$a], $joints[$b], $radius, $bodyColor);
            $this->sphere($triangles, $joints[$b], [$radius, $radius, $radius], $jointColor);
        }
        $center = $this->mul($this->add($joints['neck'], $joints['pelvis']), 0.5);
        $axis = $this->normal($this->sub($joints['neck'], $joints['pelvis']));
        $side = $this->normal($this->sub($joints['rightShoulder'], $joints['leftShoulder']));
        $depth = $this->normal($this->cross($side, $axis));
        $this->sphere($triangles, $center, [$character === 'male' ? 0.22 : 0.19, $this->length($this->sub($joints['neck'], $joints['pelvis'])) * 0.5, 0.115], $bodyColor, [$side, $axis, $depth]);
        $this->sphere($triangles, $joints['pelvis'], [$character === 'female' ? 0.18 : 0.155, 0.13, 0.12], $bodyColor, [$side, $axis, $depth]);
        $this->sphere($triangles, $joints['neck'], [0.065, 0.07, 0.065], $jointColor);
        $this->sphere($triangles, $joints['head'], [0.16, 0.18, 0.15], [205, 194, 170]);
        $direction = $this->normal($pose['head_direction']);
        $this->sphere($triangles, $this->add($joints['head'], $this->mul($direction, 0.157)), [0.035, 0.03, 0.035], [183, 171, 149]);
        $draw = [];
        $light = $this->normal([-0.4, 0.7, 1]);
        $yaw = deg2rad($pose['camera']['yaw']);
        $pitch = deg2rad($pose['camera']['pitch']);
        foreach ($triangles as [$a, $b, $c, $color]) {
            $normal = $this->normal($this->cross($this->sub($b, $a), $this->sub($c, $a)));
            $normal = $this->rotate($normal, $yaw, $pitch);
            $shade = 0.5 + 0.5 * max(0, $normal[0] * $light[0] + $normal[1] * $light[1] + $normal[2] * $light[2]);
            $points = [$project($a), $project($b), $project($c)];
            $draw[] = ['depth' => array_sum(array_column($points, 2)) / 3, 'points' => [(int) $points[0][0], (int) $points[0][1], (int) $points[1][0], (int) $points[1][1], (int) $points[2][0], (int) $points[2][1]], 'color' => array_map(fn ($value) => (int) round($value * $shade), $color)];
        }
        usort($draw, fn ($a, $b) => $a['depth'] <=> $b['depth']);
        foreach ($draw as $triangle) {
            imagefilledpolygon($image, $triangle['points'], imagecolorallocate($image, ...$triangle['color']));
        }
    }

    private function cylinder(array &$triangles, array $a, array $b, float $radius, array $color): void
    {
        $axis = $this->normal($this->sub($b, $a));
        $side = $this->normal($this->cross($axis, abs($axis[1]) < 0.9 ? [0, 1, 0] : [1, 0, 0]));
        $other = $this->normal($this->cross($axis, $side));
        for ($i = 0; $i < 16; $i++) {
            $radial = fn ($angle) => $this->add($this->mul($side, cos($angle) * $radius), $this->mul($other, sin($angle) * $radius));
            $u = $radial($i * 2 * M_PI / 16);
            $v = $radial(($i + 1) * 2 * M_PI / 16);
            $triangles[] = [$this->add($a, $u), $this->add($b, $u), $this->add($b, $v), $color];
            $triangles[] = [$this->add($a, $u), $this->add($b, $v), $this->add($a, $v), $color];
        }
    }

    private function sphere(array &$triangles, array $center, array $radii, array $color, ?array $basis = null): void
    {
        $basis ??= [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        $vertex = function ($latitude, $longitude) use ($center, $radii, $basis) {
            $coords = [cos($latitude) * cos($longitude) * $radii[0], sin($latitude) * $radii[1], cos($latitude) * sin($longitude) * $radii[2]];

            return $this->add($center, $this->add($this->mul($basis[0], $coords[0]), $this->add($this->mul($basis[1], $coords[1]), $this->mul($basis[2], $coords[2]))));
        };
        for ($lat = 0; $lat < 10; $lat++) {
            for ($lon = 0; $lon < 16; $lon++) {
                $a = $vertex(-M_PI / 2 + $lat * M_PI / 10, $lon * M_PI * 2 / 16);
                $b = $vertex(-M_PI / 2 + ($lat + 1) * M_PI / 10, $lon * M_PI * 2 / 16);
                $c = $vertex(-M_PI / 2 + ($lat + 1) * M_PI / 10, ($lon + 1) * M_PI * 2 / 16);
                $d = $vertex(-M_PI / 2 + $lat * M_PI / 10, ($lon + 1) * M_PI * 2 / 16);
                $triangles[] = [$a, $b, $c, $color];
                $triangles[] = [$a, $c, $d, $color];
            }
        }
    }

    private function rotate(array $p, float $yaw, float $pitch): array
    {
        $x = $p[0] * cos($yaw) + $p[2] * sin($yaw);
        $z = -$p[0] * sin($yaw) + $p[2] * cos($yaw);

        return [$x, $p[1] * cos($pitch) - $z * sin($pitch), $p[1] * sin($pitch) + $z * cos($pitch)];
    }

    private function add(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1], $a[2] + $b[2]];
    }

    private function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    private function mul(array $a, float $n): array
    {
        return [$a[0] * $n, $a[1] * $n, $a[2] * $n];
    }

    private function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    private function length(array $a): float
    {
        return sqrt($a[0] ** 2 + $a[1] ** 2 + $a[2] ** 2);
    }

    private function normal(array $a): array
    {
        return $this->mul($a, 1 / max(0.000001, $this->length($a)));
    }
}
