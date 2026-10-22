<?php

namespace App\Modules\Practices\Services;

/** One trusted sheet markup for the browser sheet and downloaded PDF. */
class SheetRenderer
{
    public function render(array $document, array $images, array $options = []): string
    {
        $names = (bool) ($options['show_names'] ?? false);
        $preset = $options['preset'] ?? ($names ? 'names' : 'clean');
        $landscape = ($options['orientation'] ?? 'landscape') === 'landscape';
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>'.$escape($document['title']).'</title><style>';
        $html .= '@page{size:A4 '.($landscape ? 'landscape' : 'portrait').';margin:10mm}*{box-sizing:border-box}html,body{background:white;color:#111;font-family:"DejaVu Sans",Arial,sans-serif;font-size:10px}body{margin:0;padding:0}h1{font-size:18px;text-align:center;margin:0 0 6mm}h2{font-size:12px;margin:4mm 0 1mm;page-break-after:avoid}.theme{text-align:center}.sequence-row{page-break-inside:avoid;margin:0 0 4mm}.row-layout,.poses{border-collapse:separate;border-spacing:0;width:auto;max-width:100%}.number{width:10mm;vertical-align:middle;font-size:12px;padding-right:2mm}.pose{vertical-align:middle;padding:1.5mm 1mm;position:relative;text-align:center}.pose img.figure{display:block;margin:auto;object-fit:contain}.bracket{width:1.5mm;padding:0;vertical-align:middle}.bracket img{display:block;width:1.5mm}.underlined{border-bottom:1px solid #000}.transition{font-size:15px;width:3mm;text-align:center;vertical-align:middle}.marks{font-size:13px;height:4mm;text-align:right}.caption{font-size:8px;text-align:center;overflow-wrap:break-word;word-wrap:break-word;margin-top:1mm}.custom{font-size:10px;text-align:center;overflow-wrap:break-word;word-wrap:break-word}.arrow{width:6mm;height:6mm;display:inline-block;vertical-align:middle}.row-note{margin:1mm 0 0 10mm;font-size:8px}.execution{font-size:8px;margin-top:1mm}.empty{display:block}.block-purpose{font-size:9px;margin:0 0 2mm}@media print{body{margin:0;padding:0}.sequence-row{page-break-inside:avoid}}';
        $html .= '</style></head><body><h1>'.$escape($document['title']).'</h1>';
        if ($names && ! empty($document['theme'])) {
            $html .= '<p class="theme">'.$escape($document['theme']).'</p>';
        }
        foreach ($document['blocks'] as $blockIndex => $block) {
            if ($names && $preset !== 'clean') {
                $html .= '<h2>'.$escape($block['title']).'</h2>';
                if (! empty($block['purpose'])) {
                    $html .= '<p class="block-purpose">'.$escape($block['purpose']).'</p>';
                }
            }
            foreach ($block['rows'] as $rowIndex => $row) {
                $count = max(1, count($row['cells']));
                $boundaries = 0;
                foreach ($row['cells'] as $index => $cell) {
                    $key = $cell['group_key'] ?? null;
                    $boundaries += $key && ($row['cells'][$index - 1]['group_key'] ?? null) !== $key ? 1 : 0;
                    $boundaries += $key && ($row['cells'][$index + 1]['group_key'] ?? null) !== $key ? 1 : 0;
                }
                $width = min(23, (($landscape ? 277 : 190) - 16 - $boundaries * 1.5) / $count - 5);
                $width = max(8, $width);
                $html .= '<div class="sequence-row"><table class="row-layout"><tr><td class="number">'.($block['title'] === 'СВ' ? 'СВ' : ($blockIndex + 1).'.'.($rowIndex + 1)).'</td><td><table class="poses"><tr>';
                foreach ($row['cells'] as $index => $cell) {
                    $parts = $this->parts($cell['note'] ?? null);
                    $transition = collect($parts)->first(fn ($part) => in_array($part, ['→', '↔', '=', '/'], true));
                    $key = $cell['group_key'] ?? null;
                    $classes = ['pose'];
                    if ($key && ($row['cells'][$index - 1]['group_key'] ?? null) !== $key) {
                        $html .= '<td class="bracket"><img src="'.$this->bracket(false).'" style="height:'.($width + 7).'mm" alt="["></td>';
                    }
                    if ($this->underlined($row['note'] ?? '', $index + 1, $count)) {
                        $classes[] = 'underlined';
                    }
                    $html .= '<td class="'.implode(' ', $classes).'" style="width:'.$width.'mm">';
                    $marks = array_values(array_intersect($parts, ['S', '★', 'СВ*']));
                    $html .= '<div class="marks">'.$escape(implode(' ', $marks)).'</div>';
                    if (! empty($cell['arrow_direction'])) {
                        $html .= '<img class="arrow" src="'.$this->arrow($cell['arrow_direction']).'" alt="Стрелка">';
                    }
                    if (! empty($cell['card'])) {
                        $source = $images[$cell['card_snapshot_id']] ?? null;
                        abort_unless($source, 422, 'Не найдено изображение для полного листа.');
                        $html .= '<img class="figure" src="'.$escape($source).'" style="width:'.$width.'mm;height:'.$width.'mm" alt="'.$escape($cell['card']['name']).'">';
                        if ($names) {
                            $html .= '<div class="caption" style="width:'.$width.'mm">'.$escape($cell['card']['name']).'</div>';
                        }
                    } elseif (! empty($cell['custom_name'])) {
                        $html .= '<div class="custom" style="width:'.$width.'mm;min-height:'.$width.'mm">'.$escape($cell['custom_name']).'</div>';
                    } else {
                        $html .= '<span class="empty" style="width:'.$width.'mm;height:'.$width.'mm"></span>';
                    }
                    if ($names && $preset === 'names') {
                        $caption = array_filter($parts, fn ($part) => ! in_array($part, ['S', '★', 'СВ*', '→', '↔', '=', '/'], true));
                        if ($caption) {
                            $html .= '<div class="caption" style="width:'.$width.'mm">'.$escape(implode(' · ', $caption)).'</div>';
                        }
                    }
                    if ($preset === 'teach') {
                        $timing = [];
                        if ($cell['duration_seconds'] ?? null) {
                            $timing[] = $cell['duration_seconds'].' с';
                        }
                        if ($cell['breath_count'] ?? null) {
                            $timing[] = $cell['breath_count'].' дых.';
                        }
                        if ($cell['repetitions'] ?? null) {
                            $timing[] = $cell['repetitions'].' повт.';
                        }
                        if ($cell['side'] ?? null) {
                            $timing[] = ['left' => 'левая', 'right' => 'правая', 'both' => 'обе'][$cell['side']];
                        }
                        if ($timing) {
                            $html .= '<div class="execution" style="width:'.$width.'mm">'.$escape(implode(' · ', $timing)).'</div>';
                        }
                    }
                    $html .= '</td>';
                    if ($key && ($row['cells'][$index + 1]['group_key'] ?? null) !== $key) {
                        $html .= '<td class="bracket"><img src="'.$this->bracket(true).'" style="height:'.($width + 7).'mm" alt="]"></td>';
                    }
                    if ($index < $count - 1) {
                        $continues = $this->underlined($row['note'] ?? '', $index + 1, $count) && $this->underlined($row['note'] ?? '', $index + 2, $count);
                        $html .= '<td class="transition'.($continues ? ' underlined' : '').'">'.$escape($transition ?? '').'</td>';
                    }
                }
                $html .= '</tr></table></td></tr></table>';
                $rowNote = $row['note'] ?? '';
                if ($names && $preset === 'names' && ! str_starts_with($rowNote, 'Подчёркнуто:')) {
                    $html .= '<p class="row-note">'.$escape($rowNote).'</p>';
                } elseif (! $names) {
                    $marks = array_intersect($this->parts($rowNote), ['S', '★', 'СВ*']);
                    if ($marks) {
                        $html .= '<p class="row-note">'.$escape(implode(' ', $marks)).'</p>';
                    }
                }
                $html .= '</div>';
            }
        }

        return $html.'</body></html>';
    }

    private function parts(?string $note): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[·\n]+/u', $note ?? '') ?: [])));
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

    private function bracket(bool $right): string
    {
        $path = $right ? 'M1 1H5V99H1' : 'M5 1H1V99H5';

        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="6" height="100" viewBox="0 0 6 100" preserveAspectRatio="none"><path d="'.$path.'" fill="none" stroke="#000" stroke-width="1"/></svg>');
    }

    private function arrow(string $direction): string
    {
        $paths = '<path d="'.match ($direction) {
            'up' => 'M13 23V3M13 3l-5 5M13 3l5 5',
            'down' => 'M13 3v20M13 23l-5-5M13 23l5-5',
            'left' => 'M23 13H3M3 13l5-5M3 13l5 5',
            'right' => 'M3 13h20M23 13l-5-5M23 13l-5 5',
            'both' => 'M3 13h20M3 13l5-5M3 13l5 5M23 13l-5-5M23 13l-5 5',
            default => 'M3 13h20M13 3v20M3 13l5-5M3 13l5 5M23 13l-5-5M23 13l-5 5M13 3l-5 5M13 3l5 5M13 23l-5-5M13 23l5-5',
        }.'"/>';

        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 26 26"><rect width="26" height="26" fill="white"/><g fill="none" stroke="#1769c2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</g></svg>');
    }
}
