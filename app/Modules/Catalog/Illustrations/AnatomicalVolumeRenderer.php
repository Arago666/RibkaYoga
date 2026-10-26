<?php

namespace App\Modules\Catalog\Illustrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * A separate anatomical representation of a source-bound, reviewed projected pose.
 * The pose owns every joint/path coordinate. This class only builds the human surface around it.
 */
class AnatomicalVolumeRenderer
{
    public const MODEL_REVISION = 4;

    public const REVISION = 'anatomical-volume-20261026-v2';

    private const SIZE = 1024;

    public function render(array $drawing, string $character = 'neutral', ?string $sourceSha256 = null, array $options = []): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Для анатомического представления требуется расширение PHP GD.');
        }
        if (($drawing['format'] ?? null) !== 'ribka-drawing' || ($drawing['width'] ?? null) !== self::SIZE || ($drawing['height'] ?? null) !== self::SIZE || ! in_array($drawing['version'] ?? null, [1, 2], true)) {
            throw new InvalidArgumentException('Анатомическая фигура требует исходник RibkaYoga 1024 × 1024.');
        }
        if (! in_array($character, ['neutral', 'female', 'male'], true) || ($sourceSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1)) {
            throw new InvalidArgumentException('Некорректный персонаж или SHA исходного изображения.');
        }
        $anatomy = $options['anatomy'] ?? null;
        if (! is_array($anatomy) || ($anatomy['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Требуется проверенная анатомическая схема именно этого исходника.');
        }
        if ($anatomy['diagram'] ?? false) {
            $render = (new NativeVolumeRenderer)->render($drawing, $character, $sourceSha256, ['diagram' => true]);
            $render['render_source'] = $this->provenance($drawing, $character, $sourceSha256, $options, ['diagram' => true, 'human_figures' => 0]);
            $render['metrics'] = $render['render_source']['metrics'];

            return $render;
        }
        $this->validatePose($anatomy);
        $plan = $this->plan($anatomy, $character);
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        $depth = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($image, 0, 0, 0xFFFFFF);
        imagefill($depth, 0, 0, 0);
        $palettes = [
            'skin' => $this->palette([109, 70, 48], [236, 190, 153]),
            'skin-dark' => $this->palette([89, 53, 36], [207, 152, 115]),
            'top' => $this->palette([27, 58, 46], [126, 168, 140]),
            'pants' => $this->palette([22, 43, 36], [84, 122, 101]),
            'hair' => $this->palette([26, 23, 21], [83, 66, 52]),
        ];
        foreach (array_keys($palettes) as $material) {
            $palettes['ghost-'.$material] = array_map(fn (int $color): int => $this->mix($color, 0xFFFFFF, 0.28), $palettes[$material]);
        }
        try {
            // Native supports and movement symbols are never reinterpreted as body parts.
            $ink = array_fill_keys($anatomy['ink_element_ids'] ?? [], true);
            $rings = array_fill_keys($anatomy['ring_element_ids'] ?? [], true);
            foreach ($drawing['elements'] as $element) {
                if (isset($ink[$element['id']]) || isset($rings[$element['id']])) {
                    $this->nativeInk($image, $element, (float) ($drawing['smoothing'] ?? 0));
                }
            }
            foreach ($plan['shapes'] as $shape) {
                $palette = $palettes[$shape['material']];
                if ($shape['kind'] === 'ellipsoid') {
                    $this->ellipsoid($image, $depth, $shape, $palette);
                } else {
                    $this->sweep($image, $depth, $shape, $palette);
                }
            }
            ob_start();
            imagepng($image, null, 7);
            $png = ob_get_clean();
            if (! is_string($png) || $png === '') {
                throw new RuntimeException('Не удалось сформировать анатомическое изображение.');
            }
        } finally {
            unset($image, $depth);
        }

        return ['png' => $png, 'drawing_source' => null, 'render_source' => $this->provenance($drawing, $character, $sourceSha256, $options, $plan['metrics']), 'metrics' => $plan['metrics']];
    }

    private function provenance(array $drawing, string $character, ?string $sha, array $options, array $metrics): array
    {
        return [
            'format' => 'ribka-anatomical-volume', 'version' => 1, 'model_revision' => self::MODEL_REVISION, 'renderer_revision' => self::REVISION,
            'style' => 'volume', 'character' => $character, 'source_sha256' => $sha, 'render_options' => $options,
            'drawing_sha256' => hash('sha256', json_encode($drawing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'drawing_source' => $drawing,
            'source_pose_sha256' => hash('sha256', json_encode($options['anatomy'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'projection' => ['type' => 'orthographic', 'xy' => 'reviewed joint centers, native contact tips and sampled curves; bare tips retain their source coordinates and wrists/ankles are inferred inward on that same path', 'curve_tolerance_px' => 0.15],
            'contacts' => 'Native bare line endpoints are anatomical contact tips. Explicit source wrists/ankles are marked endpoint_is_joint; no global pose resizing is applied.',
            'geometry' => 'anatomical ribcage, waist, pelvis, distinct tapered upper and lower limbs, articulated hands, feet, skull and facial landmarks',
            'depth' => 'A single drawing determines projected pose only. Hidden depth and facing are reviewed conventions recorded in the pose, not inferred anatomy.',
            'material' => 'warm skin, sage athletic top and dark green leggings; analytic diffuse lighting and depth buffering; no source-image filter',
            'metrics' => $metrics,
        ];
    }

    private function validatePose(array $pose): void
    {
        if (! is_array($pose['head'] ?? null) || ! $this->pointValid($pose['head']['center'] ?? null) || ! is_array($pose['torso']['points'] ?? null) || count($pose['torso']['points']) < 2 || count($pose['torso']['points']) > 2000) {
            throw new InvalidArgumentException('В схеме нужны точные координаты головы и оси туловища.');
        }
        foreach (['rx', 'ry'] as $axis) {
            if (! is_numeric($pose['head'][$axis] ?? null) || $pose['head'][$axis] < 2 || $pose['head'][$axis] > 256) {
                throw new InvalidArgumentException('Некорректный размер головы в анатомической схеме.');
            }
        }
        if (isset($pose['head']['facing']) && ! in_array($pose['head']['facing'], ['left', 'right', 'front', 'back'], true)) {
            throw new InvalidArgumentException('Некорректное направление лица.');
        }
        if (isset($pose['head']['depth']) && (! is_numeric($pose['head']['depth']) || ! is_finite((float) $pose['head']['depth']) || abs($pose['head']['depth']) > 180)) {
            throw new InvalidArgumentException('Некорректная глубина головы.');
        }
        foreach ($pose['torso']['points'] as $point) {
            if (! $this->pointValid($point)) {
                throw new InvalidArgumentException('Некорректная координата оси туловища.');
            }
        }
        foreach (['arms', 'legs'] as $kind) {
            if (! is_array($pose[$kind] ?? null) || count($pose[$kind]) > 4) {
                throw new InvalidArgumentException('Некорректная схема конечностей.');
            }
            foreach ($pose[$kind] as $limb) {
                if (! is_array($limb['points'] ?? null) || count($limb['points']) < 2 || count($limb['points']) > 2000) {
                    throw new InvalidArgumentException('Для конечности нужны координаты её исходной линии.');
                }
                foreach ($limb['points'] as $point) {
                    if (! $this->pointValid($point)) {
                        throw new InvalidArgumentException('Некорректная координата конечности.');
                    }
                }
                if (isset($limb['joint_index']) && (! is_int($limb['joint_index']) || $limb['joint_index'] <= 0 || $limb['joint_index'] >= count($limb['points']) - 1)) {
                    throw new InvalidArgumentException('Некорректное положение сустава.');
                }
                foreach (['hand', 'foot'] as $end) {
                    if (isset($limb[$end]) && ! $this->pointValid($limb[$end])) {
                        throw new InvalidArgumentException('Некорректное положение кисти или стопы.');
                    }
                }
                if (isset($limb['depth']) && (! is_numeric($limb['depth']) || abs($limb['depth']) > 180)) {
                    throw new InvalidArgumentException('Некорректная глубина конечности.');
                }
            }
        }
    }

    private function pointValid(mixed $point): bool
    {
        return is_array($point) && count($point) === 2 && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null) && is_finite((float) $point[0]) && is_finite((float) $point[1]) && $point[0] >= -1024 && $point[0] <= 2048 && $point[1] >= -1024 && $point[1] <= 2048;
    }

    private function plan(array $pose, string $character): array
    {
        $head = $pose['head'];
        $spine = $pose['torso']['points'];
        $length = $this->pathLength($spine);
        $sourceRadius = sqrt($head['rx'] * $head['ry']);
        $scales = [$length / 5.3];
        foreach ([['arms', 5.3], ['legs', 8.1]] as [$kind, $ratio]) {
            $longest = 0;
            foreach ($pose[$kind] as $limb) {
                if (! ($limb['ghost'] ?? false) && ($limb['phase'] ?? null) !== 'alternate') {
                    $longest = max($longest, $this->pathLength($limb['points']));
                }
            }
            if ($longest > 0) {
                $scales[] = $longest / $ratio;
            }
        }
        sort($scales);
        $anatomicalRadius = $scales[(int) floor(count($scales) / 2)];
        // A foreshortened second limb must not shrink the head and stretch the entire torso.
        // Use the full trunk's anatomical proportion rather than the compressed bounds of a folded pose.
        $radius = max($sourceRadius * 0.55, $length / 6.5, min($length / 4.8, $anatomicalRadius));
        // Source joint lengths are never stretched to a generic standing mannequin.
        $radius = max(9, min(125, $radius));
        $body = $character === 'male' ? 1.11 : ($character === 'female' ? 0.98 : 1.04);
        $waist = $character === 'female' ? 0.72 : ($character === 'male' ? 0.87 : 0.81);
        $pelvis = $character === 'female' ? 1.07 : ($character === 'male' ? 0.97 : 1.02);
        $shoulder = $character === 'male' ? 1.45 : ($character === 'female' ? 1.2 : 1.32);
        $shapes = [];
        $neck = $spine[0];
        $headCenter = $head['center'];
        $neckDirection = $this->unit($this->subtract($neck, $headCenter));
        $nativeTorsoLength = $length;
        $clavicularSample = false;
        if ($this->distance($neck, $headCenter) > $radius * 1.7) {
            // A large source head circle can leave a long connector when its surface is normalized.
            // Extend the clothed chest along that same head-to-trunk axis; retain all original joints.
            $neck = $this->add($headCenter, $this->scale($neckDirection, $radius * 1.25));
            array_unshift($spine, $neck);
            $length = $this->pathLength($spine);
            $clavicularSample = true;
        }
        $headEdge = $this->add($headCenter, $this->scale($neckDirection, min($radius * 0.8, min($head['rx'], $head['ry']) * 0.78)));
        $this->addSweep($shapes, [$headEdge, $neck], [[0, $radius * 0.24], [1, $radius * 0.29]], 'skin', 0, 0.77);

        // Rear limbs precede the trunk; depth resolves actual source crossings without modifying XY.
        foreach ($pose['legs'] as $index => $limb) {
            $this->leg($shapes, $limb, $radius, $body, $index);
        }
        foreach ($pose['arms'] as $index => $limb) {
            if (($limb['depth'] ?? $index - 1) < 0) {
                $this->arm($shapes, $limb, $radius, $body, $index);
            }
        }

        $shoulderPositions = [];
        foreach ($pose['arms'] as $limb) {
            if (! ($limb['ghost'] ?? false) && ($limb['phase'] ?? null) !== 'alternate') {
                $shoulderPositions[] = $this->nearestFraction($spine, $limb['points'][0]);
            }
        }
        sort($shoulderPositions);
        $shoulderPosition = $shoulderPositions ? max(0.15, min(0.55, $shoulderPositions[(int) floor(count($shoulderPositions) / 2)])) : 0.23;
        $ribPosition = min(0.75, $shoulderPosition + 0.20);
        $waistPosition = min(0.86, max(0.62, $ribPosition + 0.13));
        $hipPosition = min(0.92, max(0.82, $waistPosition + 0.06));
        // Shoulder girdle, ribcage, waist and pelvic bowl form a continuous clothed silhouette.
        // In a strongly foreshortened drawing, widths remain tied to the source head, not path length.
        $this->addSweep($shapes, $spine, [
            [0, $radius * 0.035], [0.045, $radius * 0.37], [$shoulderPosition * 0.65, $radius * $shoulder * 0.90], [$shoulderPosition, $radius * $shoulder],
            [$ribPosition, $radius * 1.13 * $body], [$waistPosition, $radius * $waist], [$hipPosition, $radius * 0.82 * $pelvis], [1, $radius * $pelvis],
        ], 'top', 0, 0.63);
        $pelvisPoints = $this->tailFrom($spine, $length * max(0.75, $waistPosition));
        $this->addSweep($shapes, $pelvisPoints, [[0, $radius * 0.82 * $pelvis], [0.45, $radius * 1.03 * $pelvis], [1, $radius * $pelvis]], 'pants', 0.35, 0.65);

        // A subdued seam follows the body curve and helps read a human torso at thumbnail scale.
        $frontTorso = $this->slicePath($spine, $shoulderPosition + 0.05, $waistPosition);
        $this->addSweep($shapes, $frontTorso, [[0, max(0.75, $radius * 0.018)], [1, max(0.75, $radius * 0.018)]], 'top', $radius * 0.70, 0.25);
        foreach ($pose['arms'] as $index => $limb) {
            if (($limb['depth'] ?? $index - 1) >= 0) {
                $this->arm($shapes, $limb, $radius, $body, $index);
            }
        }
        $this->head($shapes, $head, $neckDirection, $radius, $character);

        return ['shapes' => $shapes, 'metrics' => ['human_figures' => 1, 'anatomical_surfaces' => count($shapes), 'arm_paths' => count($pose['arms']), 'leg_paths' => count($pose['legs']), 'native_torso_samples' => count($pose['torso']['points']), 'derived_clavicular_sample' => $clavicularSample, 'preserved_joint_coordinates' => true, 'shoulder_axis_fraction' => round($shoulderPosition, 4), 'source_head_radius' => round($sourceRadius, 4), 'anatomical_head_radius' => round($radius, 4), 'torso_length_px' => round($nativeTorsoLength, 4), 'surface_axis_length_px' => round($length, 4), 'surface_boundary_margin_px' => 6]];
    }

    private function arm(array &$shapes, array $limb, float $r, float $body, int $index): void
    {
        $points = $limb['points'];
        [$upper, $lower] = $this->jointPaths($limb, 0.52);
        $ghost = ($limb['ghost'] ?? false) || ($limb['phase'] ?? null) === 'alternate';
        $firstShape = count($shapes);
        $z = (float) ($limb['depth'] ?? 0);
        if ($ghost) {
            $z = max(-200, $z - 160);
        }
        $fat = $r * $body;
        $handPath = null;
        if (! isset($limb['hand']) && ! ($limb['endpoint_is_joint'] ?? false)) {
            [$lower, $handPath] = $this->splitContact($lower, $r * 0.52);
        }
        $upperIndex = count($shapes);
        $this->addSweep($shapes, $upper, [[0, $fat * 0.37], [0.17, $fat * 0.43], [0.43, $fat * 0.38], [0.76, $fat * 0.28], [1, $fat * 0.19]], 'skin', $z, 0.88);
        if (($limb['front'] ?? false) && ! $ghost && isset($shapes[$upperIndex])) {
            $shapes[$upperIndex]['z_profile'] = [[0, $z], [0.27, $z + $r * 0.68], [1, $z + $r * 0.68]];
            $z += $r * 0.68;
        }
        $this->addSweep($shapes, $lower, [[0, $fat * 0.19], [0.22, $fat * 0.29], [0.53, $fat * 0.235], [0.84, $fat * 0.17], [1, $fat * 0.15]], 'skin', $z + 0.2, 0.76);
        $elbow = $upper[count($upper) - 1];
        $elbowAxis = $this->unit($this->subtract($lower[min(1, count($lower) - 1)], $elbow));
        $elbowNormal = $this->turn($elbowAxis);
        $this->addEllipsoid($shapes, $elbow, $fat * 0.20, $fat * 0.14, $fat * 0.18, atan2($elbowAxis[1], $elbowAxis[0]) - M_PI / 2, 'skin', $z + $fat * 0.025);
        $this->addSweep($shapes, [$this->add($elbow, $this->scale($elbowNormal, -$fat * 0.10)), $this->add($elbow, $this->scale($elbowNormal, $fat * 0.10))], [[0, $fat * 0.012], [1, $fat * 0.012]], 'skin-dark', $z + $fat * 0.207, 0.5);
        $wrist = $lower[count($lower) - 1];
        $direction = $this->unit($this->subtract($wrist, $lower[max(0, count($lower) - 2)]));
        $handEnd = $limb['hand'] ?? ($handPath ? $handPath[count($handPath) - 1] : $this->add($wrist, $this->scale($direction, $r * 0.52)));
        $this->hand($shapes, $wrist, $handEnd, $r, $z + 0.5, $handPath);
        if ($ghost) {
            for ($i = $firstShape; $i < count($shapes); $i++) {
                $shapes[$i]['material'] = 'ghost-'.$shapes[$i]['material'];
            }
        }
    }

    private function leg(array &$shapes, array $limb, float $r, float $body, int $index): void
    {
        $points = $limb['points'];
        [$upper, $lower] = $this->jointPaths($limb, 0.52);
        $ghost = ($limb['ghost'] ?? false) || ($limb['phase'] ?? null) === 'alternate';
        $firstShape = count($shapes);
        $z = (float) ($limb['depth'] ?? ($index === 0 ? -$r * 0.19 : $r * 0.24));
        if ($ghost) {
            $z = max(-200, $z - 160);
        }
        $fat = $r * $body;
        $footPath = null;
        if (! isset($limb['foot']) && ! ($limb['endpoint_is_joint'] ?? false)) {
            [$lower, $footPath] = $this->splitContact($lower, $r * 0.9);
        }
        $this->addSweep($shapes, $upper, [[0, $fat * 0.56], [0.18, $fat * 0.57], [0.42, $fat * 0.51], [0.73, $fat * 0.36], [1, $fat * 0.28]], 'pants', $z, 0.83);
        $this->addSweep($shapes, $lower, [[0, $fat * 0.28], [0.21, $fat * 0.385], [0.44, $fat * 0.35], [0.7, $fat * 0.255], [0.92, $fat * 0.188], [1, $fat * 0.18]], 'pants', $z + 0.1, 0.8);
        $knee = $upper[count($upper) - 1];
        $axis = $this->unit($this->subtract($lower[min(1, count($lower) - 1)], $knee));
        $this->addEllipsoid($shapes, $knee, $fat * 0.24, $fat * 0.27, $fat * 0.24, atan2($axis[1], $axis[0]) - M_PI / 2, 'pants', $z + $fat * 0.035);
        $ankle = $lower[count($lower) - 1];
        $direction = $this->unit($this->subtract($ankle, $lower[max(0, count($lower) - 2)]));
        $footDirection = $direction[1] > 0.55 ? $this->turn($direction) : $direction;
        $footEnd = $limb['foot'] ?? ($footPath ? $footPath[count($footPath) - 1] : $this->add($ankle, $this->scale($footDirection, $r * 0.9)));
        $this->foot($shapes, $ankle, $footEnd, $r, $z + 0.6, $footPath);
        if ($ghost) {
            for ($i = $firstShape; $i < count($shapes); $i++) {
                $shapes[$i]['material'] = 'ghost-'.$shapes[$i]['material'];
            }
        }
    }

    private function hand(array &$shapes, array $wrist, array $end, float $r, float $z, ?array $nativePath = null): void
    {
        $length = $this->distance($wrist, $end);
        if ($length < 1) {
            return;
        }
        $axis = $this->unit($this->subtract($end, $wrist));
        $normal = $this->turn($axis);
        $size = min($r, $length / 0.53);
        $palmPoints = $nativePath ? $this->slicePath($nativePath, 0, 0.61) : [$wrist, $this->add($wrist, $this->scale($axis, $length * 0.61))];
        $palmEnd = $palmPoints[count($palmPoints) - 1];
        $this->addSweep($shapes, $palmPoints, [[0, $size * 0.13], [0.35, $size * 0.205], [0.8, $size * 0.2], [1, $size * 0.15]], 'skin', $z, 0.52);
        for ($finger = 0; $finger < 4; $finger++) {
            $offset = ($finger - 1.5) * $size * 0.085;
            $start = $this->add($palmEnd, $this->scale($normal, $offset));
            $tip = $this->add($end, $this->scale($normal, $offset));
            $tip = $this->add($tip, $this->scale($axis, -abs($finger - 1.4) * $size * 0.07));
            $this->addSweep($shapes, [$start, $tip], [[0, $size * 0.046], [0.75, $size * 0.04], [1, $size * 0.025]], 'skin', $z + $size * 0.02, 0.66);
        }
        $thumbBase = $this->add($wrist, $this->scale($axis, $length * 0.25));
        $thumbTip = $this->add($this->add($wrist, $this->scale($axis, $length * 0.62)), $this->scale($normal, $size * 0.29));
        $this->addSweep($shapes, [$thumbBase, $thumbTip], [[0, $size * 0.085], [0.65, $size * 0.07], [1, $size * 0.04]], 'skin', $z + $size * 0.03, 0.75);
    }

    private function foot(array &$shapes, array $ankle, array $end, float $r, float $z, ?array $nativePath = null): void
    {
        $length = $this->distance($ankle, $end);
        if ($length < 1) {
            return;
        }
        $axis = $this->unit($this->subtract($end, $ankle));
        $normal = $this->turn($axis);
        $size = min($r, $length / 0.65);
        $heel = $this->add($ankle, $this->scale($axis, -$size * 0.18));
        $ball = $nativePath ? $this->slicePath($nativePath, 0, 0.73) : [$ankle, $this->add($ankle, $this->scale($axis, $length * 0.73))];
        $ball = $ball[count($ball) - 1];
        $bodyEnd = $this->add($end, $this->scale($axis, -$size * 0.105));
        $bodyPoints = $nativePath ? array_merge([$heel], $this->splitAt($nativePath, max(0, $this->pathLength($nativePath) - $size * 0.105))[0]) : [$heel, $ankle, $ball, $bodyEnd];
        $this->addSweep($shapes, $bodyPoints, [[0, $size * 0.135], [0.2, $size * 0.195], [0.4, $size * 0.14], [0.74, $size * 0.18], [1, $size * 0.105]], 'skin', $z, 0.65);
        for ($toe = 0; $toe < 5; $toe++) {
            $offset = ($toe - 2) * $size * 0.048;
            $tip = $this->add($end, $this->scale($normal, $offset));
            $tip = $this->add($tip, $this->scale($axis, -$size * (0.026 + $toe * 0.02)));
            $start = $this->add($ball, $this->scale($normal, $offset));
            $this->addSweep($shapes, [$start, $tip], [[0, $size * 0.038], [1, $size * 0.026]], 'skin', $z + $size * 0.05, 0.7);
        }
    }

    private function head(array &$shapes, array $head, array $down, float $r, string $character): void
    {
        $center = $head['center'];
        if (isset($head['down']) && $this->pointValid($head['down'])) {
            $down = $this->unit($head['down']);
        }
        $right = [$down[1], -$down[0]];
        $rotation = atan2($down[1], $down[0]) - M_PI / 2;
        $sourceRadius = max(0.01, sqrt($head['rx'] * $head['ry']));
        $rx = max($r * 0.65, min($r * 1.15, $head['rx'] / $sourceRadius * $r * 0.88));
        $ry = max($r * 0.88, min($r * 1.4, $head['ry'] / $sourceRadius * $r * 1.06));
        $facing = $head['facing'] ?? 'front';
        $faceZ = $r * 0.48 + (float) ($head['depth'] ?? 0);
        // Skull and mandibular oval overlap smoothly, without a spherical mannequin head.
        $this->addEllipsoid($shapes, $this->add($center, $this->scale($down, -$ry * 0.04)), $rx * 0.93, $ry, $r * 0.8, $rotation, 'skin', $faceZ);
        $this->addEllipsoid($shapes, $this->add($center, $this->scale($down, $ry * 0.43)), $rx * 0.72, $ry * 0.52, $r * 0.68, $rotation, 'skin', $faceZ + $r * 0.01);
        $this->addEllipsoid($shapes, $this->add($center, $this->scale($down, -$ry * 0.46)), $rx * 0.91, $ry * 0.58, $r * 0.81, $rotation, 'hair', $faceZ + $r * 0.02);
        if ($facing === 'back') {
            $this->addEllipsoid($shapes, $this->add($center, $this->scale($down, -$ry * 0.1)), $rx * 0.95, $ry * 0.96, $r * 0.81, $rotation, 'hair', $faceZ + $r * 0.04);

            return;
        }
        $profile = in_array($facing, ['left', 'right'], true);
        $sign = $facing === 'left' ? -1 : 1;
        if ($profile) {
            $front = $this->scale($right, $sign);
            $nose = $this->add($this->add($center, $this->scale($front, $rx * 0.78)), $this->scale($down, $ry * 0.1));
            $this->addEllipsoid($shapes, $nose, $r * 0.17, $r * 0.125, $r * 0.21, $rotation, 'skin', $faceZ + $r * 0.56);
            $ear = $this->add($center, $this->scale($front, -$rx * 0.44));
            $this->addEllipsoid($shapes, $ear, $r * 0.14, $r * 0.23, $r * 0.17, $rotation, 'skin-dark', $faceZ + $r * 0.73);
            $eye = $this->add($this->add($center, $this->scale($front, $rx * 0.48)), $this->scale($down, -$ry * 0.05));
            $this->addEllipsoid($shapes, $eye, $r * 0.065, $r * 0.027, $r * 0.03, $rotation, 'hair', $faceZ + $r * 0.89);
            $mouth = $this->add($this->add($center, $this->scale($front, $rx * 0.6)), $this->scale($down, $ry * 0.43));
            $this->addSweep($shapes, [$this->add($mouth, $this->scale($right, -$r * 0.095)), $this->add($mouth, $this->scale($right, $r * 0.095))], [[0, $r * 0.016], [1, $r * 0.016]], 'skin-dark', $faceZ + $r * 0.81, 0.8);
        } else {
            foreach ([-1, 1] as $side) {
                $ear = $this->add($center, $this->scale($right, $side * $rx * 0.93));
                $this->addEllipsoid($shapes, $ear, $r * 0.12, $r * 0.22, $r * 0.17, $rotation, 'skin', $faceZ + $r * 0.06);
                $eye = $this->add($this->add($center, $this->scale($right, $side * $rx * 0.36)), $this->scale($down, -$ry * 0.04));
                $this->addEllipsoid($shapes, $eye, $r * 0.095, $r * 0.027, $r * 0.025, $rotation, 'hair', $faceZ + $r * 0.91);
            }
            $nose = $this->add($center, $this->scale($down, $ry * 0.15));
            $this->addEllipsoid($shapes, $nose, $r * 0.10, $r * 0.22, $r * 0.17, $rotation, 'skin', $faceZ + $r * 0.79);
            $mouth = $this->add($center, $this->scale($down, $ry * 0.47));
            $this->addSweep($shapes, [$this->add($mouth, $this->scale($right, -$r * 0.19)), $this->add($mouth, $this->scale($right, $r * 0.19))], [[0, $r * 0.018], [1, $r * 0.018]], 'skin-dark', $faceZ + $r * 0.78, 0.8);
        }
    }

    private function addSweep(array &$shapes, array $points, array $profile, string $material, float $z = 0, float $flatness = 1): void
    {
        if (count($points) < 2 || $this->pathLength($points) < 0.01) {
            return;
        }
        $shapes[] = ['kind' => 'sweep', 'points' => $points, 'profile' => $profile, 'length' => $this->pathLength($points), 'material' => $material, 'z' => $z, 'flatness' => $flatness];
    }

    private function addEllipsoid(array &$shapes, array $center, float $rx, float $ry, float $rz, float $rotation, string $material, float $z): void
    {
        $shapes[] = compact('center', 'rx', 'ry', 'rz', 'rotation', 'material', 'z') + ['kind' => 'ellipsoid'];
    }

    private function nearestFraction(array $points, array $point): float
    {
        $length = max(0.001, $this->pathLength($points));
        $travelled = 0;
        $bestDistance = INF;
        $bestFraction = 0;
        for ($i = 1; $i < count($points); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            $segment = hypot($dx, $dy);
            $t = max(0, min(1, (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / max(0.001, $segment * $segment)));
            $distance = hypot($point[0] - $a[0] - $dx * $t, $point[1] - $a[1] - $dy * $t);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestFraction = ($travelled + $segment * $t) / $length;
            }
            $travelled += $segment;
        }

        return $bestFraction;
    }

    private function splitContact(array $lowerPath, float $nominalLength): array
    {
        $length = $this->pathLength($lowerPath);
        // Contact extraction never crosses the reviewed elbow/knee, even in foreshortened limbs.
        $contactLength = min($nominalLength, $length * 0.42);

        return $this->splitAt($lowerPath, max(0, $length - $contactLength));
    }

    private function jointPaths(array $limb, float $fraction): array
    {
        $points = $limb['points'];
        if (isset($limb['joint_index'])) {
            return [array_slice($points, 0, $limb['joint_index'] + 1), array_slice($points, $limb['joint_index'])];
        }

        return $this->splitAt($points, $this->pathLength($points) * $fraction);
    }

    private function splitAt(array $points, float $distance): array
    {
        $before = [$points[0]];
        $travelled = 0;
        for ($i = 1; $i < count($points); $i++) {
            $segment = $this->distance($points[$i - 1], $points[$i]);
            if ($travelled + $segment >= $distance) {
                $t = $segment > 0 ? max(0, min(1, ($distance - $travelled) / $segment)) : 0;
                $point = $this->add($points[$i - 1], $this->scale($this->subtract($points[$i], $points[$i - 1]), $t));
                $before[] = $point;

                return [$before, array_merge([$point], array_slice($points, $i))];
            }
            $before[] = $points[$i];
            $travelled += $segment;
        }

        return [$points, [$points[count($points) - 1], $points[count($points) - 1]]];
    }

    private function tailFrom(array $points, float $distance): array
    {
        return $this->splitAt($points, $distance)[1];
    }

    private function slicePath(array $points, float $from, float $to): array
    {
        $length = $this->pathLength($points);
        $tail = $this->tailFrom($points, $length * $from);

        return $this->splitAt($tail, $length * ($to - $from))[0];
    }

    private function sweep(\GdImage $image, \GdImage $depth, array $shape, array $palette): void
    {
        $travelled = 0;
        for ($i = 1; $i < count($shape['points']); $i++) {
            $a = $shape['points'][$i - 1];
            $b = $shape['points'][$i];
            $length = $this->distance($a, $b);
            $steps = max(1, (int) ceil($length / 6));
            for ($step = 0; $step < $steps; $step++) {
                $u = $step / $steps;
                $v = ($step + 1) / $steps;
                $start = $this->add($a, $this->scale($this->subtract($b, $a), $u));
                $end = $this->add($a, $this->scale($this->subtract($b, $a), $v));
                $ra = min($this->profile($shape['profile'], ($travelled + $length * $u) / $shape['length']), $this->surfaceLimit($start));
                $rb = min($this->profile($shape['profile'], ($travelled + $length * $v) / $shape['length']), $this->surfaceLimit($end));
                $z = isset($shape['z_profile']) ? $this->profile($shape['z_profile'], ($travelled + $length * ($u + $v) / 2) / $shape['length'], false) : $shape['z'];
                $this->solidSegment($image, $depth, $start, $end, $ra, $rb, $shape['flatness'], $z, $palette);
            }
            $travelled += $length;
        }
    }

    private function profile(array $profile, float $t, bool $radius = true): float
    {
        for ($i = 1; $i < count($profile); $i++) {
            if ($t <= $profile[$i][0]) {
                $mix = max(0, min(1, ($t - $profile[$i - 1][0]) / max(0.00001, $profile[$i][0] - $profile[$i - 1][0])));
                $mix = $mix * $mix * (3 - 2 * $mix);

                $value = $profile[$i - 1][1] + ($profile[$i][1] - $profile[$i - 1][1]) * $mix;

                return $radius ? max(0.5, $value) : $value;
            }
        }

        return $radius ? max(0.5, $profile[count($profile) - 1][1]) : $profile[count($profile) - 1][1];
    }

    private function solidSegment(\GdImage $image, \GdImage $depth, array $a, array $b, float $ra, float $rb, float $flatness, float $z, array $palette): void
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
                $squared = $nx * $nx + $ny * $ny;
                if ($squared > $r * $r) {
                    continue;
                }
                $height = sqrt(max(0, $r * $r - $squared)) * $flatness;
                $encoded = (int) round(($z + $height + 256) * 1000);
                if ($encoded <= imagecolorat($depth, $x, $y)) {
                    continue;
                }
                $nz = $height / max(0.001, $flatness * $flatness);
                $norm = max(0.001, sqrt($nx * $nx + $ny * $ny + $nz * $nz));
                $color = $this->shade($nx / $norm, $ny / $norm, $nz / $norm, $palette);
                $coverage = min(1, max(0, $r - sqrt($squared)) + 0.5);
                if ($coverage < 1) {
                    $color = $this->mix($color, imagecolorat($image, $x, $y), $coverage);
                }
                imagesetpixel($image, $x, $y, $color);
                imagesetpixel($depth, $x, $y, $encoded);
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
        $scale = min(1, max(0.01, min($cx, self::SIZE - 1 - $cx) - 6) / max(0.01, $extentX), max(0.01, min($cy, self::SIZE - 1 - $cy) - 6) / max(0.01, $extentY));
        $rx *= $scale;
        $ry *= $scale;
        $rz *= $scale;
        $extentX *= $scale;
        $extentY *= $scale;
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
                $encoded = (int) round(($shape['z'] + $height + 256) * 1000);
                if ($encoded <= imagecolorat($depth, $x, $y)) {
                    continue;
                }
                $nx = $u / ($rx * $rx);
                $ny = $v / ($ry * $ry);
                $nz = $height / ($rz * $rz);
                $norm = max(0.00001, sqrt($nx * $nx + $ny * $ny + $nz * $nz));
                $color = $this->shade(($nx * $c - $ny * $s) / $norm, ($nx * $s + $ny * $c) / $norm, $nz / $norm, $palette);
                $coverage = min(1, max(0, (1 - sqrt($q)) * min($rx, $ry)) + 0.5);
                if ($coverage < 1) {
                    $color = $this->mix($color, imagecolorat($image, $x, $y), $coverage);
                }
                imagesetpixel($image, $x, $y, $color);
                imagesetpixel($depth, $x, $y, $encoded);
            }
        }
    }

    private function palette(array $shadow, array $light): array
    {
        $palette = [];
        for ($i = 0; $i <= 512; $i++) {
            $t = $i / 512;
            $r = (int) round($shadow[0] + ($light[0] - $shadow[0]) * $t);
            $g = (int) round($shadow[1] + ($light[1] - $shadow[1]) * $t);
            $b = (int) round($shadow[2] + ($light[2] - $shadow[2]) * $t);
            $palette[$i] = ($r << 16) | ($g << 8) | $b;
        }

        return $palette;
    }

    private function shade(float $nx, float $ny, float $nz, array $palette): int
    {
        $diffuse = max(0, -0.42 * $nx - 0.53 * $ny + 0.736 * $nz);
        $fill = max(0, 0.54 * $nx + 0.15 * $ny + 0.828 * $nz);

        return $palette[(int) round(min(1, 0.17 + 0.71 * $diffuse + 0.12 * $fill) * 512)];
    }

    private function mix(int $color, int $background, float $coverage): int
    {
        $r = (int) round((($color >> 16) & 255) * $coverage + (($background >> 16) & 255) * (1 - $coverage));
        $g = (int) round((($color >> 8) & 255) * $coverage + (($background >> 8) & 255) * (1 - $coverage));
        $b = (int) round(($color & 255) * $coverage + ($background & 255) * (1 - $coverage));

        return ($r << 16) | ($g << 8) | $b;
    }

    private function nativeInk(\GdImage $image, array $element, float $smoothing): void
    {
        $points = $element['points'];
        $xs = array_column($points, 'x');
        $ys = array_column($points, 'y');
        $cx = min($xs) + max(1, max($xs) - min($xs)) / 2;
        $cy = min($ys) + max(1, max($ys) - min($ys)) / 2;
        $rotation = deg2rad((float) ($element['rotation'] ?? 0));
        if ($element['kind'] === 'ellipse') {
            $rx = max(0.5, (max($xs) - min($xs)) / 2);
            $ry = max(0.5, (max($ys) - min($ys)) / 2);
            $points = [];
            for ($i = 0; $i <= 180; $i++) {
                $points[] = ['x' => $cx + $rx * cos($i * M_PI / 90), 'y' => $cy + $ry * sin($i * M_PI / 90)];
            }
        } elseif ($element['kind'] === 'curve') {
            $points = $this->quadratic($points[0], $points[1], $points[2]);
        } elseif ($element['kind'] === 'figure') {
            // A figure is handled by the reviewed body rig, never duplicated as a black skeleton.
            return;
        } elseif ($smoothing > 0 && count($points) >= 3 && $element['kind'] === 'pen') {
            $samples = [$points[0]];
            $from = $points[0];
            for ($i = 1; $i < count($points) - 1; $i++) {
                $to = ['x' => ($points[$i]['x'] + $points[$i + 1]['x']) / 2, 'y' => ($points[$i]['y'] + $points[$i + 1]['y']) / 2];
                array_push($samples, ...array_slice($this->quadratic($from, $points[$i], $to), 1));
                $from = $to;
            }
            $samples[] = $points[count($points) - 1];
            $points = $samples;
        }
        $color = $element['kind'] === 'eraser' ? 0xFFFFFF : hexdec(ltrim($element['color'] ?? '#111111', '#'));
        $width = max(1, (int) round($element['width']));
        imagesetthickness($image, $width);
        $previous = null;
        foreach ($points as $point) {
            $dx = $point['x'] - $cx;
            $dy = $point['y'] - $cy;
            $world = [$cx + $dx * cos($rotation) - $dy * sin($rotation), $cy + $dx * sin($rotation) + $dy * cos($rotation)];
            imagefilledellipse($image, (int) round($world[0]), (int) round($world[1]), $width, $width, $color);
            if ($previous !== null) {
                imageline($image, (int) round($previous[0]), (int) round($previous[1]), (int) round($world[0]), (int) round($world[1]), $color);
            }
            $previous = $world;
        }
        imagesetthickness($image, 1);
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

    private function surfaceLimit(array $point): float
    {
        // Only surface thickness is bounded; source joint centers and path coordinates stay fixed.
        return max(0.6, min($point[0], $point[1], self::SIZE - 1 - $point[0], self::SIZE - 1 - $point[1]) - 6);
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

    private function unit(array $vector): array
    {
        $length = max(0.0001, hypot($vector[0], $vector[1]));

        return [$vector[0] / $length, $vector[1] / $length];
    }

    private function add(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1]];
    }

    private function subtract(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1]];
    }

    private function scale(array $vector, float $scale): array
    {
        return [$vector[0] * $scale, $vector[1] * $scale];
    }

    private function turn(array $vector): array
    {
        return [-$vector[1], $vector[0]];
    }
}
