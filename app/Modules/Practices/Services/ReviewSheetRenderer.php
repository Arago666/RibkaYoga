<?php

namespace App\Modules\Practices\Services;

/** One paginated drawing surface for the preview and PDF, including annotations. */
class ReviewSheetRenderer
{
    private int $width;

    private int $height;

    private int $margin = 62;

    private int $top = 125;

    private int $bottom;

    private string $font;

    private string $bold;

    private array $positions = [];

    private array $pages = [];

    private array $details = [];

    private array $thumbnails = [];

    private array $comments = [];

    private array $groups = [];

    private array $cellGroups = [];

    private array $inline = [];

    private array $references = [];

    public function render(array $review, array $images, array $options = []): string
    {
        abort_unless(function_exists('imagettftext'), 503, 'Для листа разбора требуется поддержка изображений и шрифтов.');
        $landscape = ($options['orientation'] ?? 'landscape') === 'landscape';
        [$this->width, $this->height] = $landscape ? [1684, 1190] : [1190, 1684];
        $this->bottom = $this->height - 74;
        $this->font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $this->bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        abort_unless(is_file($this->font) && is_file($this->bold), 503, 'Не найден шрифт листа разбора.');
        $this->positions = $this->pages = $this->details = $this->thumbnails = $this->comments = $this->groups = $this->cellGroups = $this->inline = $this->references = [];
        $document = $review['document'];
        $annotations = $review['annotations'];
        $rows = [];
        $columns = 1;
        foreach ($document['blocks'] as $bi => $block) {
            foreach ($block['rows'] as $ri => $row) {
                $label = ($bi + 1).'.'.($ri + 1);
                $columns = max($columns, count($row['cells']));
                foreach ($row['cells'] as $ci => $cell) {
                    $this->references[$cell['id']] = $label.'.'.($ci + 1);
                }
                $rows[] = ['row' => $row, 'label' => $label, 'block' => $block['title'], 'first' => $ri === 0];
            }
        }
        $slot = min(182, ($this->width - $this->margin * 2 - 74) / max(1, $columns));
        $figure = min(124, $slot - 22);
        $textWidth = $slot - 14;
        $this->prepareAnnotations($annotations, $textWidth);
        foreach ($rows as $entry) {
            foreach ($entry['row']['cells'] as $cell) {
                if (! empty($cell['custom_name']) && count($this->wrap($cell['custom_name'], 16, $figure - 12)) > max(1, (int) floor(($figure - 20) / 21))) {
                    $this->details[] = ['label' => 'Ячейка '.$this->references[$cell['id']], 'body' => $cell['custom_name'], 'color' => '#6b726d'];
                }
            }
        }
        $this->prepareImages($images);
        $page = 0;
        $y = $this->top;
        $this->pages[] = ['rows' => [], 'details' => []];
        foreach ($rows as $entry) {
            $inlineHeight = 0;
            foreach ($entry['row']['cells'] as $cell) {
                $height = 0;
                foreach ($this->inline[$cell['id']] ?? [] as $item) {
                    $height += count($item['lines']) * 22 + 22;
                }
                $inlineHeight = max($inlineHeight, $height);
            }
            $nameHeight = ! empty($options['show_names']) ? 58 : 0;
            $rowHeight = 86 + $figure + $nameHeight + $inlineHeight + 26;
            if ($y + $rowHeight > $this->bottom && $y > $this->top) {
                $page = $this->newPage();
                $y = $this->top;
            }
            $entry += ['y' => $y, 'height' => $rowHeight, 'slot' => $slot, 'figure' => $figure, 'names' => (bool) ($options['show_names'] ?? false)];
            $this->pages[$page]['rows'][] = $entry;
            foreach ($entry['row']['cells'] as $ci => $cell) {
                $x = $this->margin + 74 + $ci * $slot + ($slot - $figure) / 2;
                $this->positions[$cell['id']] = ['page' => $page, 'x' => $x, 'y' => $y + 74, 'size' => $figure, 'row_y' => $y, 'row' => $entry['label']];
            }
            $y += $rowHeight;
        }
        foreach ($this->groups as $group) {
            $refs = array_map(fn ($id) => $this->references[$id] ?? '?', $group['cell_ids']);
            $this->details[] = ['label' => $group['label'].' · Асаны '.implode(', ', $refs), 'body' => $group['body'], 'color' => $group['color']];
        }
        foreach ($annotations['arrows'] as $i => $arrow) {
            $from = $this->positions[$arrow['from_cell_id']] ?? null;
            $to = $this->positions[$arrow['to_cell_id']] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            $label = 'С'.($i + 1).' · '.$this->references[$arrow['from_cell_id']].' → '.$this->references[$arrow['to_cell_id']];
            if ($from['page'] !== $to['page']) {
                $label .= ' · страницы '.($from['page'] + 1).' → '.($to['page'] + 1);
            }
            $this->details[] = ['label' => $label, 'body' => $arrow['body'] ?? '', 'color' => $arrow['color']];
        }
        if ($this->details) {
            $y += 20;
            if ($y + 90 > $this->bottom) {
                $page = $this->newPage();
                $y = $this->top;
            }
            $this->pages[$page]['details'][] = ['heading' => true, 'text' => 'Комментарии и связи', 'y' => $y];
            $y += 44;
            foreach ($this->details as $detail) {
                $lines = $this->wrap($detail['label'].($detail['body'] !== '' ? "\n".$detail['body'] : ''), 18, $this->width - $this->margin * 2 - 34);
                $offset = 0;
                while ($offset < count($lines)) {
                    if ($y + 54 > $this->bottom) {
                        $page = $this->newPage();
                        $y = $this->top;
                    }
                    $available = max(1, (int) floor(($this->bottom - $y - 24) / 26));
                    $part = array_slice($lines, $offset, $available);
                    $this->pages[$page]['details'][] = ['heading' => false, 'lines' => $part, 'y' => $y, 'color' => $detail['color'], 'continuation' => $offset > 0];
                    $offset += count($part);
                    $y += count($part) * 26 + 24;
                }
            }
        }
        $pageImages = [];
        $bytes = 0;
        foreach ($this->pages as $index => $layout) {
            $canvas = imagecreatetruecolor($this->width, $this->height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            $this->drawHeader($canvas, $review, $index);
            foreach ($layout['rows'] as $entry) {
                $this->drawRow($canvas, $entry);
            }
            foreach ($annotations['arrows'] as $i => $arrow) {
                $this->drawConnection($canvas, $arrow, $i, $index);
            }
            foreach ($layout['details'] as $detail) {
                $this->drawDetail($canvas, $detail);
            }
            $footer = 'Разбор практики · Страница '.($index + 1).' из '.count($this->pages);
            $this->text($canvas, $footer, $this->margin, $this->height - 32, 15, '#6b726d');
            ob_start();
            imagepng($canvas, null, 5);
            $png = ob_get_clean();
            unset($canvas);
            abort_unless(is_string($png), 422, 'Не удалось подготовить страницу разбора.');
            $bytes += strlen($png);
            abort_if($bytes > 48 * 1024 * 1024, 422, 'Разбор слишком велик для одного PDF. Сократите число пометок или разделите его на несколько разборов.');
            $pageImages[] = 'data:image/png;base64,'.base64_encode($png);
        }
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        [$mmWidth, $mmHeight] = $landscape ? [297, 210] : [210, 297];
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="ribka-review-id" content="'.$escape($review['id']).'"><meta name="ribka-review-revision" content="'.(int) $review['revision'].'"><title>'.$escape($review['title']).'</title><style>';
        $html .= '@page{size:A4 '.($landscape ? 'landscape' : 'portrait').';margin:0}html,body{margin:0;padding:0;background:#f6f8f6}.review-page{margin:12px auto;max-width:'.($landscape ? '1122' : '794').'px;box-shadow:0 2px 20px #dde4df}.review-page img{display:block;width:100%;height:auto}.review-title{display:none}@media print{html,body{background:#fff}.review-page{box-shadow:none;margin:0;width:'.$mmWidth.'mm;height:'.($mmHeight - 0.25).'mm;page-break-after:always;page-break-inside:avoid}.review-page:last-child{page-break-after:auto}.review-page img{width:'.$mmWidth.'mm;height:'.($mmHeight - 0.25).'mm}}</style></head><body><h1 class="review-title">'.$escape($review['title']).'</h1>';
        foreach ($pageImages as $i => $source) {
            $html .= '<div class="review-page"><img src="'.$source.'" alt="Разбор практики, страница '.($i + 1).'"></div>';
        }

        return $html.'</body></html>';
    }

    private function newPage(): int
    {
        abort_if(count($this->pages) >= 200, 422, 'Разбор содержит больше 200 страниц. Разделите его на несколько разборов.');
        $this->pages[] = ['rows' => [], 'details' => []];

        return count($this->pages) - 1;
    }

    private function prepareAnnotations(array $annotations, float $width): void
    {
        $order = array_flip(array_keys($this->references));
        foreach ($annotations['comments'] as $i => $comment) {
            $comment['label'] = 'К'.($i + 1);
            $this->comments[$comment['cell_id']][] = $comment;
        }
        foreach ($annotations['groups'] as $i => $group) {
            $group['label'] = 'Г'.($i + 1);
            usort($group['cell_ids'], fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
            $this->groups[] = $group;
            foreach ($group['cell_ids'] as $cellId) {
                $this->cellGroups[$cellId][] = $group;
            }
            if ($group['body'] !== '') {
                $this->comments[$group['cell_ids'][0]][] = $group;
            }
        }
        foreach ($this->comments as $cellId => $entries) {
            foreach ($entries as $i => $entry) {
                $lines = $this->wrap($entry['label'].' '.$entry['body'], 16, $width - 10);
                $truncated = count($lines) > 5 || $i >= 2;
                if ($i < 2) {
                    $this->inline[$cellId][] = ['lines' => $truncated ? [...array_slice($lines, 0, 3), ...$this->wrap('См. '.$entry['label'].' ниже', 16, $width - 10)] : $lines, 'color' => $entry['color']];
                }
                if ($truncated && str_starts_with($entry['label'], 'К')) {
                    $this->details[] = ['label' => $entry['label'].' · Асана '.$this->references[$cellId], 'body' => $entry['body'], 'color' => $entry['color']];
                }
                if ($i === 2) {
                    $this->inline[$cellId][] = ['lines' => ['Ещё комментарии -', 'см. раздел ниже'], 'color' => '#6b726d'];
                }
            }
        }
    }

    private function prepareImages(array $images): void
    {
        $bytes = 0;
        foreach ($images as $id => $source) {
            abort_unless(is_string($source) && str_starts_with($source, 'data:image/'), 422, 'Не удалось открыть защищённое изображение разбора.');
            $binary = base64_decode(explode(',', $source, 2)[1] ?? '', true);
            abort_unless(is_string($binary), 422, 'Повреждённое изображение разбора.');
            $size = @getimagesizefromstring($binary);
            abort_unless($size && $size[0] * $size[1] <= 24000000, 422, 'Изображение слишком велико для PDF разбора.');
            $image = @imagecreatefromstring($binary);
            abort_unless($image, 422, 'Не удалось прочитать изображение разбора.');
            $ratio = min(1, 360 / max(imagesx($image), imagesy($image)));
            $thumb = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), imagesx($image), imagesy($image));
            ob_start();
            imagepng($thumb, null, 5);
            $png = ob_get_clean();
            unset($image, $thumb, $binary);
            abort_unless(is_string($png), 422);
            $bytes += strlen($png);
            abort_if($bytes > 32 * 1024 * 1024, 422, 'Слишком много крупных изображений для PDF разбора.');
            $this->thumbnails[$id] = $png;
        }
    }

    private function drawHeader(\GdImage $canvas, array $review, int $page): void
    {
        $title = $this->wrap($review['title'], 27, $this->width - $this->margin * 2);
        foreach (array_slice($title, 0, 2) as $i => $line) {
            $this->text($canvas, $line, $this->margin, 45 + $i * 34, 27, '#151916', true);
        }
        $label = 'Исходная практика: '.$review['document']['title'].' · версия '.$review['source_revision'];
        $this->text($canvas, $this->short($label, 15, $this->width - $this->margin * 2), $this->margin, 108, 15, '#6b726d');
        $color = $this->color($canvas, '#e3e8e4');
        imageline($canvas, $this->margin, 117, $this->width - $this->margin, 117, $color);
    }

    private function drawRow(\GdImage $canvas, array $entry): void
    {
        $row = $entry['row'];
        $y = $entry['y'];
        $slot = $entry['slot'];
        $figure = $entry['figure'];
        $this->text($canvas, $entry['label'], $this->margin, $y + 82, 19, '#151916', true);
        if ($entry['first']) {
            $this->text($canvas, $this->short($entry['block'], 15, $this->width - $this->margin * 2 - 100), $this->margin + 74, $y + 18, 15, '#6b726d');
        }
        foreach ($row['cells'] as $i => $cell) {
            $position = $this->positions[$cell['id']];
            $x = $position['x'];
            $fy = $position['y'];
            $groups = $this->cellGroups[$cell['id']] ?? [];
            if ($groups) {
                imagefilledrectangle($canvas, (int) ($x - 5), (int) ($fy - 5), (int) ($x + $figure + 5), (int) ($fy + $figure + 5), $this->color($canvas, $groups[0]['color'], 0.2));
                foreach (array_slice($groups, 0, 3) as $gi => $group) {
                    imagefilledrectangle($canvas, (int) ($x - 5), (int) ($fy + $figure + 3 + $gi * 4), (int) ($x + $figure + 5), (int) ($fy + $figure + 6 + $gi * 4), $this->color($canvas, $group['color']));
                }
                $groupLabel = implode(' ', array_column(array_slice($groups, 0, 3), 'label')).(count($groups) > 3 ? ' +'.(count($groups) - 3) : '');
                $this->text($canvas, $this->short($groupLabel, 13, $figure), $x, $fy - 10, 13, $groups[0]['color']);
            }
            if (! empty($cell['card'])) {
                $source = $this->thumbnails[$cell['card_snapshot_id']] ?? null;
                abort_unless($source, 422, 'Не найден рисунок сохранённой асаны.');
                $image = imagecreatefromstring($source);
                if ($groups) {
                    imagecolortransparent($image, imagecolorallocate($image, 255, 255, 255));
                }
                $ratio = min(($figure - 10) / imagesx($image), ($figure - 10) / imagesy($image));
                $w = imagesx($image) * $ratio;
                $h = imagesy($image) * $ratio;
                imagecopyresampled($canvas, $image, (int) ($x + ($figure - $w) / 2), (int) ($fy + ($figure - $h) / 2), 0, 0, (int) $w, (int) $h, imagesx($image), imagesy($image));
                unset($image);
            } elseif (! empty($cell['custom_name'])) {
                $lines = $this->wrap($cell['custom_name'], 16, $figure - 12);
                $max = max(1, (int) floor(($figure - 20) / 21));
                foreach (array_slice($lines, 0, $max) as $li => $line) {
                    $this->text($canvas, $line, $x + 6, $fy + 29 + $li * 21, 16, '#151916');
                }
                if (count($lines) > $max) {
                    imagefilledrectangle($canvas, (int) $x, (int) ($fy + $figure - 18), (int) ($x + $figure), (int) ($fy + $figure), $this->color($canvas, '#ffffff'));
                    $this->text($canvas, 'См. ячейку '.$this->references[$cell['id']], $x + 3, $fy + $figure - 2, 10, '#6b726d');
                }
            }
            $this->text($canvas, (string) ($i + 1), $x + 3, $fy + 15, 12, '#6b726d');
            if (! empty($cell['arrow_direction'])) {
                $this->drawPositionArrow($canvas, $cell['arrow_direction'], $x + $figure - 29, $fy + 2);
            }
            $groupKey = $cell['group_key'] ?? null;
            if ($groupKey && ($row['cells'][$i - 1]['group_key'] ?? null) !== $groupKey) {
                $this->bracket($canvas, $x - 7, $fy - 4, $figure + 8, false);
            }
            if ($groupKey && ($row['cells'][$i + 1]['group_key'] ?? null) !== $groupKey) {
                $this->bracket($canvas, $x + $figure + 7, $fy - 4, $figure + 8, true);
            }
            $parts = preg_split('/[·\n]+/u', $cell['note'] ?? '') ?: [];
            $parts = array_map('trim', $parts);
            $signs = array_intersect($parts, ['S', '★', 'СВ*']);
            if ($signs) {
                $this->text($canvas, implode(' ', $signs), $x + $figure - 25, $fy + $figure - 3, 16, '#151916');
            }
            if ($i < count($row['cells']) - 1) {
                $transition = array_values(array_intersect($parts, ['→', '↔', '=', '/']));
                if ($transition) {
                    $markSize = 18;
                    while ($markSize > 10 && $this->width($transition[0], $markSize) > $slot - $figure - 3) {
                        $markSize--;
                    }
                    $markX = $x + $figure + ($slot - $figure - $this->width($transition[0], $markSize)) / 2;
                    $this->text($canvas, $transition[0], $markX, $fy + $figure / 2, $markSize, '#151916');
                }
            }
            if ($this->underlined($row['note'] ?? '', $i + 1, count($row['cells']))) {
                imageline($canvas, (int) ($x - 4), (int) ($fy + $figure + 11), (int) ($x + $figure + 4), (int) ($fy + $figure + 11), $this->color($canvas, '#151916'));
            }
            $textY = $fy + $figure + 28;
            if ($entry['names'] && ! empty($cell['card'])) {
                foreach (array_slice($this->wrap($cell['card']['name'], 14, $slot - 16), 0, 2) as $line) {
                    $this->text($canvas, $line, $x - ($slot - $figure) / 2 + 7, $textY, 14, '#6b726d');
                    $textY += 21;
                }
                $textY = max($textY, $fy + $figure + 67);
            }
            foreach ($this->inline[$cell['id']] ?? [] as $item) {
                $left = $x - ($slot - $figure) / 2 + 7;
                imagefilledrectangle($canvas, (int) $left, (int) ($textY - 15), (int) ($left + 3), (int) ($textY + count($item['lines']) * 22 - 17), $this->color($canvas, $item['color']));
                foreach ($item['lines'] as $line) {
                    $this->text($canvas, $line, $left + 9, $textY, 16, '#151916');
                    $textY += 22;
                }
                $textY += 22;
            }
        }
        imageline($canvas, $this->margin, (int) ($y + $entry['height'] - 4), $this->width - $this->margin, (int) ($y + $entry['height'] - 4), $this->color($canvas, '#edf0ed'));
    }

    private function drawConnection(\GdImage $canvas, array $arrow, int $index, int $page): void
    {
        $from = $this->positions[$arrow['from_cell_id']] ?? null;
        $to = $this->positions[$arrow['to_cell_id']] ?? null;
        if (! $from || ! $to || ($from['page'] !== $page && $to['page'] !== $page)) {
            return;
        }
        $color = $this->color($canvas, $arrow['color']);
        $label = 'С'.($index + 1);
        $sx = $from['x'] + $from['size'] / 2;
        $sy = $from['y'] - 2;
        $tx = $to['x'] + $to['size'] / 2;
        $ty = $to['y'] - 2;
        imagesetthickness($canvas, 3);
        if ($from['page'] !== $to['page']) {
            $position = $from['page'] === $page ? $from : $to;
            $x = $position['x'] + $position['size'] / 2;
            $y = $position['y'] - 2;
            $caption = $label.($from['page'] === $page ? ' → стр. '.($to['page'] + 1) : ' со стр. '.($from['page'] + 1));
            imageline($canvas, (int) $x, (int) $y, (int) $x, (int) ($y - 22), $color);
            $captionX = max($this->margin, min($x - 4, $this->width - $this->margin - $this->width($caption, 13)));
            $this->text($canvas, $caption, $captionX, $y - 27, 13, $arrow['color'], true);
            if ($to['page'] === $page) {
                $this->arrowHead($canvas, $x, $y, M_PI / 2, $color);
            }
        } elseif ($from['row'] === $to['row']) {
            $lane = $from['row_y'] + 40 - ($index % 3) * 6;
            $this->bezier($canvas, [$sx, $sy], [$sx, $lane], [$tx, $lane], [$tx, $ty], $color);
            $this->arrowHead($canvas, $tx, $ty, M_PI / 2, $color);
            $this->text($canvas, $label, ($sx + $tx) / 2 - 8, $lane + 3, 13, $arrow['color'], true);
        } else {
            $laneX = $this->width - $this->margin + 16 + ($index % 3) * 9;
            $startLane = $from['row_y'] + 40 + ($index % 3) * 6;
            $endLane = $to['row_y'] + 40 + ($index % 3) * 6;
            $points = [[$sx, $sy], [$sx, $startLane], [$laneX, $startLane], [$laneX, $endLane], [$tx, $endLane], [$tx, $ty]];
            for ($i = 1; $i < count($points); $i++) {
                imageline($canvas, (int) $points[$i - 1][0], (int) $points[$i - 1][1], (int) $points[$i][0], (int) $points[$i][1], $color);
            }
            $this->arrowHead($canvas, $tx, $ty, M_PI / 2, $color);
            $this->text($canvas, $label, $laneX - 25, ($startLane + $endLane) / 2, 13, $arrow['color'], true);
        }
        if ($from['page'] === $page) {
            imagefilledellipse($canvas, (int) $sx, (int) $sy, 7, 7, $color);
        }
        imagesetthickness($canvas, 1);
    }

    private function drawDetail(\GdImage $canvas, array $detail): void
    {
        if ($detail['heading']) {
            $this->text($canvas, $detail['text'], $this->margin, $detail['y'] + 24, 23, '#151916', true);

            return;
        }
        $y = $detail['y'] + 21;
        imagefilledrectangle($canvas, $this->margin, (int) ($y - 17), $this->margin + 4, (int) ($y + (count($detail['lines']) - 1) * 26 + 5), $this->color($canvas, $detail['color']));
        foreach ($detail['lines'] as $index => $line) {
            $this->text($canvas, $line, $this->margin + 18, $y + $index * 26, 18, '#151916', $index === 0 && ! $detail['continuation']);
        }
    }

    private function drawPositionArrow(\GdImage $canvas, string $direction, float $x, float $y): void
    {
        imagefilledrectangle($canvas, (int) $x, (int) $y, (int) ($x + 27), (int) ($y + 27), $this->color($canvas, '#ffffff'));
        $color = $this->color($canvas, '#1769c2');
        imagesetthickness($canvas, 2);
        $horizontal = in_array($direction, ['left', 'right', 'both', 'cross'], true);
        $vertical = in_array($direction, ['up', 'down', 'cross'], true);
        if ($horizontal) {
            imageline($canvas, (int) ($x + 3), (int) ($y + 13), (int) ($x + 24), (int) ($y + 13), $color);
        }
        if ($vertical) {
            imageline($canvas, (int) ($x + 13), (int) ($y + 3), (int) ($x + 13), (int) ($y + 24), $color);
        }
        if (in_array($direction, ['right', 'both', 'cross'], true)) {
            $this->arrowHead($canvas, $x + 24, $y + 13, 0, $color, 5);
        }
        if (in_array($direction, ['left', 'both', 'cross'], true)) {
            $this->arrowHead($canvas, $x + 3, $y + 13, M_PI, $color, 5);
        }
        if (in_array($direction, ['up', 'cross'], true)) {
            $this->arrowHead($canvas, $x + 13, $y + 3, -M_PI / 2, $color, 5);
        }
        if (in_array($direction, ['down', 'cross'], true)) {
            $this->arrowHead($canvas, $x + 13, $y + 24, M_PI / 2, $color, 5);
        }
        imagesetthickness($canvas, 1);
    }

    private function bracket(\GdImage $canvas, float $x, float $y, float $height, bool $right): void
    {
        $color = $this->color($canvas, '#151916');
        $edge = $right ? -6 : 6;
        imagesetthickness($canvas, 2);
        imageline($canvas, (int) $x, (int) $y, (int) ($x + $edge), (int) $y, $color);
        imageline($canvas, (int) $x, (int) $y, (int) $x, (int) ($y + $height), $color);
        imageline($canvas, (int) $x, (int) ($y + $height), (int) ($x + $edge), (int) ($y + $height), $color);
        imagesetthickness($canvas, 1);
    }

    private function bezier(\GdImage $canvas, array $p0, array $p1, array $p2, array $p3, int $color): void
    {
        $previous = $p0;
        for ($i = 1; $i <= 40; $i++) {
            $t = $i / 40;
            $u = 1 - $t;
            $point = [$u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $p1[0] + 3 * $u * $t ** 2 * $p2[0] + $t ** 3 * $p3[0], $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $p1[1] + 3 * $u * $t ** 2 * $p2[1] + $t ** 3 * $p3[1]];
            imageline($canvas, (int) $previous[0], (int) $previous[1], (int) $point[0], (int) $point[1], $color);
            $previous = $point;
        }
    }

    private function arrowHead(\GdImage $canvas, float $x, float $y, float $angle, int $color, float $size = 9): void
    {
        foreach ([-0.48, 0.48] as $offset) {
            imageline($canvas, (int) $x, (int) $y, (int) ($x - cos($angle + $offset) * $size), (int) ($y - sin($angle + $offset) * $size), $color);
        }
    }

    private function text(\GdImage $canvas, string $text, float $x, float $baseline, int $size, string $hex, bool $bold = false): void
    {
        imagettftext($canvas, $size, 0, (int) $x, (int) $baseline, $this->color($canvas, $hex), $bold ? $this->bold : $this->font, $text);
    }

    private function width(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->font, $text);

        return $box ? (int) ($box[2] - $box[0]) : 0;
    }

    private function short(string $text, int $size, float $width): string
    {
        if ($this->width($text, $size) <= $width) {
            return $text;
        }
        $characters = mb_str_split($text);
        while ($characters && $this->width(implode('', $characters).'...', $size) > $width) {
            array_pop($characters);
        }

        return implode('', $characters).'...';
    }

    private function wrap(string $text, int $size, float $width): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($this->width($candidate, $size) <= $width) {
                    $line = $candidate;

                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (mb_str_split($word) as $character) {
                    if ($line !== '' && $this->width($line.$character, $size) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $character;
                }
            }
            $lines[] = $line;
        }

        return $lines ?: [''];
    }

    private function color(\GdImage $canvas, string $hex, float $opacity = 1): int
    {
        if (! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            $hex = '#16783a';
        }
        $rgb = array_map(fn ($part) => (int) round(hexdec($part) * $opacity + 255 * (1 - $opacity)), str_split(substr($hex, 1), 2));

        return imagecolorallocate($canvas, ...$rgb);
    }

    private function underlined(string $note, int $position, int $count): bool
    {
        if (! preg_match('/^Подчёркнуто:\s*([\d\s–;]+)$/u', $note, $match)) {
            return false;
        }
        foreach (explode(';', $match[1]) as $range) {
            if (! preg_match('/^\s*(\d+)(?:\s*–\s*(\d+))?\s*$/u', $range, $bounds)) {
                continue;
            }
            $first = (int) $bounds[1];
            $last = (int) ($bounds[2] ?? $bounds[1]);
            if ($first >= 1 && $last <= $count && $last >= $first && $position >= $first && $position <= $last) {
                return true;
            }
        }

        return false;
    }
}
