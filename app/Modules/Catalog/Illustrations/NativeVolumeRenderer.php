<?php

namespace App\Modules\Catalog\Illustrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * Matte 3D swept solids with an orthographic projection pinned to the native XY paths.
 * A drawing does not describe hidden anatomy: depth at crossings is a documented convention.
 */
class NativeVolumeRenderer
{
    public const MODEL_REVISION = 2;

    public const REVISION = 'native-volume-20261023-v1';

    private const SIZE = 1024;

    private const FIGURE_CONNECTIONS = [[1, 2], [2, 3], [3, 4], [1, 5], [5, 6], [6, 7], [1, 8], [8, 9], [9, 10], [10, 11], [8, 12], [12, 13], [13, 14]];

    /** The caller supplies a validated, immutable ribka-drawing and its concrete image SHA. */
    public function render(array $drawing, string $character = 'neutral', ?string $sourceSha256 = null, array $options = []): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Для объёмного представления требуется расширение PHP GD.');
        }
        if (($drawing['format'] ?? null) !== 'ribka-drawing' || ! in_array($drawing['version'] ?? null, [1, 2], true) || ($drawing['width'] ?? null) !== self::SIZE || ($drawing['height'] ?? null) !== self::SIZE || empty($drawing['elements'])) {
            throw new InvalidArgumentException('Для объёмного представления нужен исходник RibkaYoga 1024 × 1024.');
        }
        if (! in_array($character, ['neutral', 'female', 'male'], true) || ($sourceSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1)) {
            throw new InvalidArgumentException('Некорректный вариант фигуры или SHA исходного изображения.');
        }
        if (array_diff(array_keys($options), ['diagram', 'ring_element_ids', 'disable_inferred_trunk', 'tube_element_ids', 'suppress_fill_element_ids']) || (array_key_exists('diagram', $options) && ! is_bool($options['diagram'])) || (array_key_exists('disable_inferred_trunk', $options) && $options['disable_inferred_trunk'] !== true)) {
            throw new InvalidArgumentException('Некорректные параметры объёмного представления.');
        }
        $rings = $options['ring_element_ids'] ?? [];
        if (! is_array($rings) || ! array_is_list($rings) || count($rings) > 1000 || count(array_unique($rings, SORT_REGULAR)) !== count($rings)) {
            throw new InvalidArgumentException('Некорректный список объёмных колец.');
        }
        $ellipseIds = array_column(array_filter($drawing['elements'], fn ($element) => $element['kind'] === 'ellipse'), 'id');
        foreach ($rings as $id) {
            if (! is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) !== 1 || ! in_array($id, $ellipseIds, true)) {
                throw new InvalidArgumentException('Объёмное кольцо должно указывать на эллипс этого точного исходника.');
            }
        }
        $tubes = $options['tube_element_ids'] ?? [];
        if (! is_array($tubes) || ! array_is_list($tubes) || count($tubes) > 1000 || count(array_unique($tubes, SORT_REGULAR)) !== count($tubes)) {
            throw new InvalidArgumentException('Некорректный список объёмных штрихов.');
        }
        $pathIds = array_column(array_filter($drawing['elements'], fn ($element) => in_array($element['kind'], ['pen', 'line', 'curve', 'figure'], true)), 'id');
        foreach ($tubes as $id) {
            if (! is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) !== 1 || ! in_array($id, $pathIds, true)) {
                throw new InvalidArgumentException('Объёмный штрих должен указывать на линию этого точного исходника.');
            }
        }
        $suppressedFills = $options['suppress_fill_element_ids'] ?? [];
        if (! is_array($suppressedFills) || ! array_is_list($suppressedFills) || count($suppressedFills) > 1000 || count(array_unique($suppressedFills, SORT_REGULAR)) !== count($suppressedFills)) {
            throw new InvalidArgumentException('Некорректный список штрихов заливки головы.');
        }
        $penIds = array_column(array_filter($drawing['elements'], fn ($element) => $element['kind'] === 'pen'), 'id');
        foreach ($suppressedFills as $id) {
            if (! is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) !== 1 || ! in_array($id, $penIds, true)) {
                throw new InvalidArgumentException('Заливка головы должна указывать на перо этого точного исходника.');
            }
        }
        if (array_intersect($tubes, $suppressedFills)) {
            throw new InvalidArgumentException('Один штрих не может одновременно быть объёмной линией и пропущенной заливкой.');
        }

        $plan = $this->plan($drawing, $character, $options);
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        $depth = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($image, 0, 0, 0xFFFFFF);
        imagefill($depth, 0, 0, 0);
        $palette = $this->palette();
        try {
            foreach ($plan['shapes'] as $shape) {
                if ($shape['kind'] === 'ellipsoid') {
                    $this->ellipsoid($image, $depth, $shape, $palette);
                } elseif ($shape['kind'] === 'erase') {
                    $this->erase($image, $depth, $shape);
                } elseif ($shape['kind'] === 'ink') {
                    $this->ink($image, $shape);
                } elseif ($shape['kind'] === 'panel') {
                    $this->panel($image, $depth, $shape, $palette);
                } elseif ($shape['kind'] === 'skip') {
                    continue;
                } else {
                    $this->tube($image, $depth, $shape, $palette);
                }
            }
            ob_start();
            imagepng($image, null, 7);
            $png = ob_get_clean();
            if (! is_string($png) || $png === '') {
                throw new RuntimeException('Не удалось сформировать объёмное изображение.');
            }
        } finally {
            unset($image, $depth);
        }

        $nativeJson = json_encode($drawing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'png' => $png,
            // A solid is a separate representation, never an editable replacement for the source.
            'drawing_source' => null,
            'render_source' => [
                'format' => 'ribka-native-volume', 'version' => 1, 'model_revision' => self::MODEL_REVISION, 'renderer_revision' => self::REVISION,
                'style' => 'volume', 'character' => $character, 'source_sha256' => $sourceSha256,
                'render_options' => $options,
                'drawing_sha256' => hash('sha256', $nativeJson), 'drawing_source' => $drawing,
                'projection' => ['type' => 'orthographic', 'xy' => 'unchanged native world coordinates', 'curve_tolerance_px' => 0.15],
                'geometry' => 'analytic swept spherical solids and ellipsoids with per-pixel surface normals and depth buffering',
                'depth' => 'No hidden anatomical depth is inferred. Crossing priority follows source element order with a small fixed offset.',
                'material' => 'matte warm neutral clay; diffuse lighting; no raster blur or source-image filter',
                'metrics' => $plan['metrics'],
            ],
            'metrics' => $plan['metrics'],
        ];
    }

    private function plan(array $drawing, string $character, array $options): array
    {
        $diagram = $options['diagram'] ?? false;
        $shapes = [];
        $headRadius = null;
        $head = null;
        $curveCount = 0;
        $pathCount = 0;
        foreach ($drawing['elements'] as $index => $element) {
            $points = $element['points'];
            $bounds = $this->bounds($element);
            $rotation = deg2rad((float) $element['rotation']);
            $world = fn (array $point): array => $this->world($point, $bounds, $rotation);
            $z = min(10, $index * 0.035);
            if ($element['kind'] === 'ellipse') {
                $center = $world(['x' => $bounds[0] + $bounds[2] / 2, 'y' => $bounds[1] + $bounds[3] / 2]);
                $rx = $bounds[2] / 2;
                $ry = $bounds[3] / 2;
                if ($diagram || in_array($element['id'], $options['ring_element_ids'] ?? [], true)) {
                    $samples = [];
                    $steps = max(16, (int) ceil(M_PI / acos(max(-1, 1 - 0.15 / max($rx, $ry, 0.5)))));
                    for ($i = 0; $i <= $steps; $i++) {
                        $angle = $i * 2 * M_PI / $steps;
                        $samples[] = $world(['x' => $bounds[0] + $rx + cos($angle) * $rx, 'y' => $bounds[1] + $ry + sin($angle) * $ry]);
                    }
                    $shapes[] = $this->path($samples, $element, $z, 'diagram');
                    $pathCount++;

                    continue;
                }
                if ($head === null && min($rx, $ry) >= 4) {
                    $head = $center;
                    $headRadius = sqrt($rx * $ry);
                }
                $shapes[] = ['kind' => 'ellipsoid', 'primitive' => 'ellipse', 'center' => $center, 'rx' => $rx, 'ry' => $ry, 'rz' => sqrt($rx * $ry) * 0.88, 'rotation' => $rotation, 'width' => (float) $element['width'], 'color' => $element['color'] ?? '#111111', 'z' => $z, 'source_id' => $element['id']];

                continue;
            }
            if ($element['kind'] === 'figure') {
                $radius = max(3, hypot($points[0]['x'] - $points[1]['x'], $points[0]['y'] - $points[1]['y']) * 0.45);
                $center = $world($points[0]);
                if (! $diagram && $head === null) {
                    $head = $center;
                    $headRadius = $radius;
                }
                if ($diagram) {
                    $samples = [];
                    $steps = max(16, (int) ceil(M_PI / acos(max(-1, 1 - 0.15 / $radius))));
                    for ($i = 0; $i <= $steps; $i++) {
                        $angle = $i * 2 * M_PI / $steps;
                        $samples[] = $world(['x' => $points[0]['x'] + cos($angle) * $radius, 'y' => $points[0]['y'] + sin($angle) * $radius]);
                    }
                    $shapes[] = $this->path($samples, $element, $z, 'diagram');
                } else {
                    $shapes[] = ['kind' => 'ellipsoid', 'center' => $center, 'rx' => $radius, 'ry' => $radius, 'rz' => $radius * 0.88, 'rotation' => $rotation, 'z' => $z, 'source_id' => $element['id']];
                }
                $neck = $points[1];
                $distance = max(1, hypot($neck['x'] - $points[0]['x'], $neck['y'] - $points[0]['y']));
                $headEdge = ['x' => $points[0]['x'] + ($neck['x'] - $points[0]['x']) * $radius / $distance, 'y' => $points[0]['y'] + ($neck['y'] - $points[0]['y']) * $radius / $distance];
                $shapes[] = $this->path([$world($headEdge), $world($neck)], $element, $z, $diagram ? 'diagram' : 'neck');
                foreach (self::FIGURE_CONNECTIONS as [$a, $b]) {
                    $shapes[] = $this->path([$world($points[$a]), $world($points[$b])], $element, $z, $diagram ? 'diagram' : ($a === 1 && $b === 8 ? 'trunk' : 'limb'));
                    $pathCount++;
                }

                continue;
            }
            $samples = $this->samples($element, (float) ($drawing['smoothing'] ?? 0));
            $samples = array_map($world, $samples);
            $shapes[] = $this->path($samples, $element, $z, $element['kind'] === 'eraser' ? 'erase' : ($diagram ? 'diagram' : 'limb'));
            $curveCount += $element['kind'] === 'curve' ? 1 : 0;
            $pathCount++;
        }
        $headRadius ??= 40;
        $headRadius = max(8, min(130, $headRadius));
        if (! empty($options['suppress_fill_element_ids'])) {
            if ($head === null || $diagram) {
                throw new InvalidArgumentException('Для пропуска плоской заливки требуется сохранённая объёмная геометрия головы.');
            }
            foreach ($shapes as &$shape) {
                if (in_array($shape['source_id'], $options['suppress_fill_element_ids'], true)) {
                    $shape['kind'] = 'skip';
                }
            }
            unset($shape);
        }
        if (! $diagram) {
            $this->context($shapes, $head, $headRadius);
        }
        $restoredTubes = 0;
        foreach ($shapes as &$shape) {
            if ($shape['kind'] === 'ink' && isset($shape['points']) && in_array($shape['source_id'], $options['tube_element_ids'] ?? [], true)) {
                $shape['kind'] = 'tube';
                $restoredTubes++;
            }
        }
        unset($shape);
        $disableInferredTrunk = $options['disable_inferred_trunk'] ?? false;
        $trunkIndex = ! $diagram && ! $disableInferredTrunk && $head ? $this->trunk($shapes, $head, $headRadius) : null;
        $factor = $character === 'male' ? 1.045 : ($character === 'female' ? 0.98 : 1);
        $segmentCount = 0;
        foreach ($shapes as $index => &$shape) {
            if (in_array($shape['kind'], ['ellipsoid', 'ink', 'skip', 'panel'], true)) {
                continue;
            }
            if ($index === $trunkIndex) {
                $shape['role'] = 'trunk';
                if ($this->distance($shape['points'][0], $head) > $this->distance($shape['points'][count($shape['points']) - 1], $head)) {
                    $shape['points'] = array_reverse($shape['points']);
                }
            }
            $length = $this->pathLength($shape['points']);
            $base = $diagram || $shape['role'] === 'diagram' ? $shape['width'] * 0.7 : max($shape['width'] * 0.65, $headRadius * 0.31) * $factor;
            // Short native details stay short details rather than becoming oversized spheres.
            $base = min($base, max($shape['width'] * 0.75, $length * 0.27));
            $shape['radius'] = max(2, $base);
            $shape['head_radius'] = $headRadius * $factor;
            $shape['length'] = $length;
            $segmentCount += max(1, count($shape['points']) - 1);
        }
        unset($shape);
        if ($segmentCount > 100000) {
            throw new InvalidArgumentException('Исходник слишком сложен для локального объёмного рендера.');
        }

        return ['shapes' => $shapes, 'metrics' => ['native_elements' => count($drawing['elements']), 'native_curves' => $curveCount, 'paths' => $pathCount, 'sampled_segments' => $segmentCount, 'head_radius_px' => $diagram ? null : round($headRadius, 3), 'diagram' => $diagram, 'inferred_trunk' => $trunkIndex !== null, 'inferred_trunk_disabled' => $disableInferredTrunk, 'reviewed_tube_elements' => count($options['tube_element_ids'] ?? []), 'restored_tube_elements' => $restoredTubes, 'reviewed_suppressed_fill_elements' => count($options['suppress_fill_element_ids'] ?? []), 'ink_elements' => count(array_filter($shapes, fn ($shape) => $shape['kind'] === 'ink')), 'suppressed_fill_elements' => count(array_filter($shapes, fn ($shape) => $shape['kind'] === 'skip')), 'source_projection_transform' => 'identity']];
    }

    private function path(array $points, array $element, float $z, string $role): array
    {
        return ['kind' => $role === 'erase' ? 'erase' : 'tube', 'role' => $role, 'native_kind' => $element['kind'], 'points' => $points, 'width' => (float) $element['width'], 'color' => $element['color'] ?? '#111111', 'z' => $z, 'source_id' => $element['id']];
    }

    /** Keep actual annotation components, repeated wall hatching and small dots as source ink. */
    private function context(array &$shapes, ?array $head, float $radius): void
    {
        if ($head === null) {
            return;
        }
        $headShape = null;
        foreach ($shapes as $index => &$shape) {
            if ($shape['kind'] === 'ellipsoid') {
                if ($headShape === null) {
                    $headShape = $shape;
                } elseif (max($shape['rx'], $shape['ry']) < $radius * 0.3) {
                    $shape['kind'] = 'ink';
                }
            }
        }
        unset($shape);
        foreach ($shapes as $index => $shape) {
            if ($shape['kind'] !== 'tube' || $shape['native_kind'] !== 'line') {
                continue;
            }
            $a = $shape['points'][0];
            $b = $shape['points'][1];
            $length = $this->distance($a, $b);
            if ($length < $radius * 5.5 || (abs($a[0] - $b[0]) > 1.5 && abs($a[1] - $b[1]) > 1.5)) {
                continue;
            }
            $hatches = [];
            foreach ($shapes as $otherIndex => $other) {
                if ($otherIndex === $index || $other['kind'] !== 'tube' || $other['native_kind'] !== 'line') {
                    continue;
                }
                $p = $other['points'][0];
                $q = $other['points'][1];
                $otherLength = $this->distance($p, $q);
                if ($otherLength <= 1 || $otherLength > $radius * 2) {
                    continue;
                }
                $parallel = abs((($b[0] - $a[0]) * ($q[0] - $p[0]) + ($b[1] - $a[1]) * ($q[1] - $p[1])) / ($length * $otherLength));
                $near = min($this->segmentDistance($p, $a, $b), $this->segmentDistance($q, $a, $b));
                if ($parallel > 0.2 && $parallel < 0.92 && $near < $radius * 0.3) {
                    $hatches[] = $otherIndex;
                }
            }
            if (count($hatches) >= 4) {
                $shapes[$index]['kind'] = 'ink';
                foreach ($hatches as $hatch) {
                    $shapes[$hatch]['kind'] = 'ink';
                }
            }
        }

        // Native solid fills are encoded as tightly spaced horizontal scan lines, not extra limbs.
        $groups = [];
        $group = [];
        $before = null;
        foreach ($shapes as $index => $shape) {
            $horizontal = $shape['kind'] === 'tube' && $shape['native_kind'] === 'line' && abs($shape['points'][0][1] - $shape['points'][1][1]) < 0.05;
            $y = $horizontal ? $shape['points'][0][1] : null;
            if (! $horizontal || ($before !== null && ($y <= $before || $y - $before > $shape['width'] * 0.8))) {
                if (count($group) >= 8) {
                    $groups[] = $group;
                }
                $group = [];
            }
            if ($horizontal) {
                $group[] = $index;
            }
            $before = $y;
        }
        if (count($group) >= 8) {
            $groups[] = $group;
        }
        foreach ($groups as $indices) {
            $insideHead = true;
            $rows = [];
            foreach ($indices as $index) {
                $shape = $shapes[$index];
                $a = $shape['points'][0];
                $b = $shape['points'][1];
                $rows[] = [min($a[0], $b[0]), max($a[0], $b[0]), $a[1]];
                foreach ([$a, $b] as $point) {
                    $dx = $point[0] - $headShape['center'][0];
                    $dy = $point[1] - $headShape['center'][1];
                    $u = $dx * cos($headShape['rotation']) + $dy * sin($headShape['rotation']);
                    $v = -$dx * sin($headShape['rotation']) + $dy * cos($headShape['rotation']);
                    $insideHead = $insideHead && ($u / $headShape['rx']) ** 2 + ($v / $headShape['ry']) ** 2 <= 1.05;
                }
                $shapes[$index]['kind'] = 'skip';
            }
            if (! $insideHead) {
                $first = $indices[0];
                $shapes[$first] = ['kind' => 'panel', 'rows' => $rows, 'z' => $shapes[$first]['z'], 'radius' => $radius, 'source_ids' => array_map(fn ($index) => $shapes[$index]['source_id'], $indices)];
            }
        }

        // Flood the native contact graph from the head; detached arrows and notes retain their ink.
        $body = [];
        foreach ($shapes as $index => $shape) {
            if ($shape['kind'] !== 'tube') {
                continue;
            }
            $near = INF;
            for ($i = 1; $i < count($shape['points']); $i++) {
                $near = min($near, $this->segmentDistance($head, $shape['points'][$i - 1], $shape['points'][$i]));
            }
            if ($near < $radius * 1.85 || $shape['native_kind'] === 'figure') {
                $body[$index] = true;
            }
        }
        do {
            $changed = false;
            foreach ($shapes as $index => $shape) {
                if ($shape['kind'] !== 'tube' || isset($body[$index])) {
                    continue;
                }
                foreach (array_keys($body) as $otherIndex) {
                    $other = $shapes[$otherIndex];
                    if ($this->pathsTouch($shape['points'], $other['points'], max(6, $radius * 0.45, min($shape['width'], $other['width']) * 1.4))) {
                        $body[$index] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        foreach ($shapes as $index => &$shape) {
            if ($shape['kind'] === 'tube' && $shape['role'] !== 'diagram' && ! isset($body[$index])) {
                $shape['kind'] = 'ink';
            }
        }
        unset($shape);
    }

    private function pathsTouch(array $a, array $b, float $tolerance): bool
    {
        foreach ([[$a, $b], [$b, $a]] as [$points, $segments]) {
            foreach ([$points[0], $points[count($points) - 1]] as $point) {
                for ($i = 1; $i < count($segments); $i++) {
                    if ($this->segmentDistance($point, $segments[$i - 1], $segments[$i]) <= $tolerance) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function ink(\GdImage $image, array $shape): void
    {
        $points = $shape['points'] ?? [];
        if (($shape['primitive'] ?? null) === 'ellipse') {
            for ($i = 0; $i <= 120; $i++) {
                $angle = $i * 2 * M_PI / 120;
                $u = cos($angle) * $shape['rx'];
                $v = sin($angle) * $shape['ry'];
                $points[] = [$shape['center'][0] + $u * cos($shape['rotation']) - $v * sin($shape['rotation']), $shape['center'][1] + $u * sin($shape['rotation']) + $v * cos($shape['rotation'])];
            }
        }
        $color = hexdec(ltrim($shape['color'] ?? '#111111', '#'));
        $width = max(1, (int) round($shape['width']));
        imagesetthickness($image, $width);
        foreach ($points as $index => $point) {
            imagefilledellipse($image, (int) round($point[0]), (int) round($point[1]), $width, $width, $color);
            if ($index > 0) {
                $before = $points[$index - 1];
                imageline($image, (int) round($before[0]), (int) round($before[1]), (int) round($point[0]), (int) round($point[1]), $color);
            }
        }
        imagesetthickness($image, 1);
    }

    /** A native scan-line fill becomes an extruded volume with its original exact row envelope. */
    private function panel(\GdImage $image, \GdImage $depth, array $shape, array $palette): void
    {
        $rows = $shape['rows'];
        $index = 1;
        for ($y = max(0, (int) ceil($rows[0][2])); $y <= min(self::SIZE - 1, (int) floor($rows[count($rows) - 1][2])); $y++) {
            while ($index < count($rows) - 1 && $rows[$index][2] < $y) {
                $index++;
            }
            $a = $rows[$index - 1];
            $b = $rows[$index];
            $t = ($y - $a[2]) / max(0.0001, $b[2] - $a[2]);
            $left = $a[0] + ($b[0] - $a[0]) * $t;
            $right = $a[1] + ($b[1] - $a[1]) * $t;
            $rx = max(0.5, ($right - $left) / 2);
            $cx = ($left + $right) / 2;
            $rz = min($shape['radius'] * 0.65, $rx * 0.75);
            for ($x = max(0, (int) ceil($left)); $x <= min(self::SIZE - 1, (int) floor($right)); $x++) {
                $u = ($x - $cx) / $rx;
                $height = $rz * sqrt(max(0, 1 - $u * $u));
                $encodedDepth = (int) round(($shape['z'] + $height + 256) * 1000);
                if ($encodedDepth <= imagecolorat($depth, $x, $y)) {
                    continue;
                }
                $nx = $u / $rx;
                $nz = $height / max(0.0001, $rz * $rz);
                $norm = max(0.00001, hypot($nx, $nz));
                imagesetpixel($image, $x, $y, $this->shade($nx / $norm, 0, $nz / $norm, $palette));
                imagesetpixel($depth, $x, $y, $encodedDepth);
            }
        }
    }

    /** A trunk can only be a long existing path touching the head and carrying native branches. */
    private function trunk(array $shapes, array $head, float $radius): ?int
    {
        $candidates = [];
        foreach ($shapes as $index => $shape) {
            if ($shape['kind'] !== 'tube' || $shape['role'] !== 'limb') {
                continue;
            }
            $points = $shape['points'];
            $length = $this->pathLength($points);
            $endDistance = min($this->distance($points[0], $head), $this->distance($points[count($points) - 1], $head));
            if ($length < $radius * 2.5 || abs($endDistance - $radius) > $radius * 0.45) {
                continue;
            }
            $branches = 0;
            foreach ($shapes as $otherIndex => $other) {
                if ($index === $otherIndex || $other['kind'] !== 'tube') {
                    continue;
                }
                foreach ([$other['points'][0], $other['points'][count($other['points']) - 1]] as $endpoint) {
                    for ($i = 1; $i < count($points); $i++) {
                        if ($this->segmentDistance($endpoint, $points[$i - 1], $points[$i]) < max(8, $radius * 0.23)) {
                            $branches++;
                            break;
                        }
                    }
                }
            }
            // The closest actual attachment to the head takes precedence over a long nearby arm.
            $candidate = 100 / (1 + abs($endDistance - $radius) / $radius) + min(12, $length / $radius) + min(6, $branches) + ($shape['native_kind'] === 'curve' ? 22 : 0);
            if ($branches >= 2) {
                $candidates[$index] = $candidate;
            }
        }
        arsort($candidates);
        if (count($candidates) >= 2 && array_values($candidates)[0] - array_values($candidates)[1] < 8) {
            return null;
        }

        return $candidates ? array_key_first($candidates) : null;
    }

    private function tube(\GdImage $image, \GdImage $depth, array $shape, array $palette): void
    {
        $points = $shape['points'];
        if (count($points) === 1) {
            $points[] = $points[0];
        }
        $length = max(0.001, $shape['length']);
        $travelled = 0;
        for ($i = 1; $i < count($points); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $segmentLength = $this->distance($a, $b);
            // Subdivision permits smooth variable-radius anatomy along even a single native line.
            $steps = max(1, (int) ceil($segmentLength / 9));
            for ($step = 0; $step < $steps; $step++) {
                $u = $step / $steps;
                $v = ($step + 1) / $steps;
                $start = [$a[0] + ($b[0] - $a[0]) * $u, $a[1] + ($b[1] - $a[1]) * $u];
                $end = [$a[0] + ($b[0] - $a[0]) * $v, $a[1] + ($b[1] - $a[1]) * $v];
                $ra = $this->radius($shape, ($travelled + $segmentLength * $u) / $length);
                $rb = $this->radius($shape, ($travelled + $segmentLength * $v) / $length);
                $this->capsule($image, $depth, $start, $end, $ra, $rb, $shape['z'], $palette);
            }
            $travelled += $segmentLength;
        }
    }

    private function radius(array $shape, float $t): float
    {
        if ($shape['role'] === 'diagram') {
            return $shape['radius'];
        }
        if ($shape['role'] === 'neck') {
            return $shape['head_radius'] * 0.23;
        }
        if ($shape['role'] === 'trunk') {
            $profile = [[0, 0.23], [0.12, 0.3], [0.3, 0.68], [0.52, 0.63], [0.77, 0.46], [1, 0.51]];
            for ($i = 1; $i < count($profile); $i++) {
                if ($t <= $profile[$i][0]) {
                    $mix = ($t - $profile[$i - 1][0]) / ($profile[$i][0] - $profile[$i - 1][0]);
                    $mix = $mix * $mix * (3 - 2 * $mix);

                    return $shape['head_radius'] * ($profile[$i - 1][1] + ($profile[$i][1] - $profile[$i - 1][1]) * $mix);
                }
            }
        }

        return $shape['radius'] * (0.91 + 0.12 * sin(M_PI * $t));
    }

    /** Front intersection of the ray with a swept spherical solid, retaining a true depth buffer. */
    private function capsule(\GdImage $image, \GdImage $depth, array $a, array $b, float $ra, float $rb, float $z, array $palette): void
    {
        $radius = max($ra, $rb);
        $minX = max(0, (int) floor(min($a[0], $b[0]) - $radius - 1));
        $maxX = min(self::SIZE - 1, (int) ceil(max($a[0], $b[0]) + $radius + 1));
        $minY = max(0, (int) floor(min($a[1], $b[1]) - $radius - 1));
        $maxY = min(self::SIZE - 1, (int) ceil(max($a[1], $b[1]) + $radius + 1));
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSquared = $dx * $dx + $dy * $dy;
        $dr = $rb - $ra;
        $denominator = $lengthSquared - $dr * $dr;
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $t = $denominator > 0 ? max(0, min(1, (($x + 0.5 - $a[0]) * $dx + ($y + 0.5 - $a[1]) * $dy + $ra * $dr) / $denominator)) : ($rb > $ra ? 1 : 0);
                $r = $ra + ($rb - $ra) * $t;
                $nx = $x + 0.5 - $a[0] - $dx * $t;
                $ny = $y + 0.5 - $a[1] - $dy * $t;
                $distanceSquared = $nx * $nx + $ny * $ny;
                if ($distanceSquared > $r * $r) {
                    continue;
                }
                $height = sqrt(max(0, $r * $r - $distanceSquared));
                $encodedDepth = (int) round(($z + $height + 256) * 1000);
                if ($encodedDepth <= imagecolorat($depth, $x, $y)) {
                    continue;
                }
                $color = $this->shade($nx / $r, $ny / $r, $height / $r, $palette);
                $coverage = min(1, max(0, $r - sqrt($distanceSquared)) + 0.5);
                if ($coverage < 1) {
                    $color = $this->mix($color, imagecolorat($image, $x, $y), $coverage);
                }
                imagesetpixel($image, $x, $y, $color);
                imagesetpixel($depth, $x, $y, $encodedDepth);
            }
        }
    }

    private function ellipsoid(\GdImage $image, \GdImage $depth, array $shape, array $palette): void
    {
        [$cx, $cy] = $shape['center'];
        $rx = max(0.5, $shape['rx']);
        $ry = max(0.5, $shape['ry']);
        $rz = max(0.5, $shape['rz']);
        $c = cos($shape['rotation']);
        $s = sin($shape['rotation']);
        $extentX = sqrt($rx * $rx * $c * $c + $ry * $ry * $s * $s);
        $extentY = sqrt($rx * $rx * $s * $s + $ry * $ry * $c * $c);
        for ($y = max(0, (int) floor($cy - $extentY - 1)); $y <= min(self::SIZE - 1, (int) ceil($cy + $extentY + 1)); $y++) {
            for ($x = max(0, (int) floor($cx - $extentX - 1)); $x <= min(self::SIZE - 1, (int) ceil($cx + $extentX + 1)); $x++) {
                $dx = $x + 0.5 - $cx;
                $dy = $y + 0.5 - $cy;
                $u = $dx * $c + $dy * $s;
                $v = -$dx * $s + $dy * $c;
                $q = ($u / $rx) ** 2 + ($v / $ry) ** 2;
                if ($q > 1) {
                    continue;
                }
                $height = $rz * sqrt(max(0, 1 - $q));
                $encodedDepth = (int) round(($shape['z'] + $height + 256) * 1000);
                if ($encodedDepth <= imagecolorat($depth, $x, $y)) {
                    continue;
                }
                $nx = $u / ($rx * $rx);
                $ny = $v / ($ry * $ry);
                $nz = $height / ($rz * $rz);
                $length = max(0.00001, sqrt($nx * $nx + $ny * $ny + $nz * $nz));
                $color = $this->shade(($nx * $c - $ny * $s) / $length, ($nx * $s + $ny * $c) / $length, $nz / $length, $palette);
                $coverage = min(1, max(0, (1 - sqrt($q)) * min($rx, $ry)) + 0.5);
                if ($coverage < 1) {
                    $color = $this->mix($color, imagecolorat($image, $x, $y), $coverage);
                }
                imagesetpixel($image, $x, $y, $color);
                imagesetpixel($depth, $x, $y, $encodedDepth);
            }
        }
    }

    private function erase(\GdImage $image, \GdImage $depth, array $shape): void
    {
        foreach ([$image, $depth] as $target) {
            $color = $target === $image ? 0xFFFFFF : 0;
            imagesetthickness($target, max(1, (int) round($shape['width'])));
            foreach ($shape['points'] as $index => $point) {
                imagefilledellipse($target, (int) round($point[0]), (int) round($point[1]), (int) round($shape['width']), (int) round($shape['width']), $color);
                if ($index > 0) {
                    $before = $shape['points'][$index - 1];
                    imageline($target, (int) round($before[0]), (int) round($before[1]), (int) round($point[0]), (int) round($point[1]), $color);
                }
            }
            imagesetthickness($target, 1);
        }
    }

    private function palette(): array
    {
        $palette = [];
        for ($i = 0; $i <= 512; $i++) {
            $light = $i / 512;
            // A broad diffuse key, soft ambient fill and warm shadow replace shiny highlights.
            $r = (int) round(108 + 126 * $light);
            $g = (int) round(79 + 123 * $light);
            $b = (int) round(61 + 115 * $light);
            $palette[$i] = ($r << 16) | ($g << 8) | $b;
        }

        return $palette;
    }

    private function shade(float $nx, float $ny, float $nz, array $palette): int
    {
        $diffuse = max(0, -0.42 * $nx - 0.53 * $ny + 0.736 * $nz);
        $fill = max(0, 0.54 * $nx + 0.15 * $ny + 0.828 * $nz);
        $light = min(1, 0.17 + 0.71 * $diffuse + 0.12 * $fill);

        return $palette[(int) round($light * 512)];
    }

    private function mix(int $color, int $background, float $coverage): int
    {
        $r = (int) round((($color >> 16) & 255) * $coverage + (($background >> 16) & 255) * (1 - $coverage));
        $g = (int) round((($color >> 8) & 255) * $coverage + (($background >> 8) & 255) * (1 - $coverage));
        $b = (int) round(($color & 255) * $coverage + ($background & 255) * (1 - $coverage));

        return ($r << 16) | ($g << 8) | $b;
    }

    private function samples(array $element, float $smoothing): array
    {
        $points = $element['points'];
        if ($element['kind'] === 'curve') {
            return $this->quadratic($points[0], $points[1], $points[2]);
        }
        if ($element['kind'] === 'line' || count($points) < 2) {
            return $points;
        }
        $points = $this->simplify($points, $smoothing / 25);
        if (! $smoothing || count($points) < 3) {
            return $points;
        }
        $samples = [$points[0]];
        $from = $points[0];
        for ($i = 1; $i < count($points) - 1; $i++) {
            $to = ['x' => ($points[$i]['x'] + $points[$i + 1]['x']) / 2, 'y' => ($points[$i]['y'] + $points[$i + 1]['y']) / 2];
            array_push($samples, ...array_slice($this->quadratic($from, $points[$i], $to), 1));
            $from = $to;
        }
        $samples[] = $points[count($points) - 1];

        return $samples;
    }

    private function quadratic(array $a, array $control, array $b): array
    {
        $curvature = hypot($a['x'] - 2 * $control['x'] + $b['x'], $a['y'] - 2 * $control['y'] + $b['y']);
        $steps = max(1, (int) ceil(sqrt($curvature / (4 * 0.15))));
        $samples = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $u = 1 - $t;
            $samples[] = ['x' => $u * $u * $a['x'] + 2 * $u * $t * $control['x'] + $t * $t * $b['x'], 'y' => $u * $u * $a['y'] + 2 * $u * $t * $control['y'] + $t * $t * $b['y']];
        }

        return $samples;
    }

    private function simplify(array $points, float $tolerance): array
    {
        if (count($points) < 3 || ! $tolerance) {
            return $points;
        }
        $keep = [0 => true, count($points) - 1 => true];
        $queue = [[0, count($points) - 1]];
        while ($queue) {
            [$first, $last] = array_pop($queue);
            $maximum = $tolerance;
            $chosen = -1;
            for ($i = $first + 1; $i < $last; $i++) {
                $distance = $this->segmentDistance([$points[$i]['x'], $points[$i]['y']], [$points[$first]['x'], $points[$first]['y']], [$points[$last]['x'], $points[$last]['y']]);
                if ($distance > $maximum) {
                    $maximum = $distance;
                    $chosen = $i;
                }
            }
            if ($chosen >= 0) {
                $keep[$chosen] = true;
                $queue[] = [$first, $chosen];
                $queue[] = [$chosen, $last];
            }
        }
        ksort($keep);

        return array_values(array_intersect_key($points, $keep));
    }

    private function bounds(array $element): array
    {
        $xs = array_column($element['points'], 'x');
        $ys = array_column($element['points'], 'y');
        if ($element['kind'] === 'figure') {
            $head = $element['points'][0];
            $neck = $element['points'][1];
            $radius = max(3, hypot($head['x'] - $neck['x'], $head['y'] - $neck['y']) * 0.45);
            array_push($xs, $head['x'] - $radius, $head['x'] + $radius);
            array_push($ys, $head['y'] - $radius, $head['y'] + $radius);
        }
        $x = min($xs);
        $y = min($ys);

        return [$x, $y, max(1, max($xs) - $x), max(1, max($ys) - $y)];
    }

    private function world(array $point, array $bounds, float $rotation): array
    {
        $cx = $bounds[0] + $bounds[2] / 2;
        $cy = $bounds[1] + $bounds[3] / 2;
        $dx = $point['x'] - $cx;
        $dy = $point['y'] - $cy;

        return [$cx + $dx * cos($rotation) - $dy * sin($rotation), $cy + $dx * sin($rotation) + $dy * cos($rotation)];
    }

    private function pathLength(array $points): float
    {
        $length = 0;
        for ($i = 1; $i < count($points); $i++) {
            $length += $this->distance($points[$i - 1], $points[$i]);
        }

        return $length;
    }

    private function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }

    private function segmentDistance(array $point, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / $lengthSquared)) : 0;

        return hypot($point[0] - $a[0] - $dx * $t, $point[1] - $a[1] - $dy * $t);
    }
}
