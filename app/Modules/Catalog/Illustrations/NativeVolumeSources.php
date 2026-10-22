<?php

namespace App\Modules\Catalog\Illustrations;

use App\Modules\Catalog\PublicApi\DrawingSources;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Geometry belongs to this exact source image; names never select or infer a pose. */
class NativeVolumeSources
{
    private ?array $traces = null;

    private ?array $roles = null;

    public function __construct(private DrawingSources $drawings) {}

    public function resolve(mixed $source, string $imageSha256): ?array
    {
        $provenance = 'catalog-native';
        if ($source === null) {
            $trace = $this->traces()[$imageSha256] ?? null;
            if (! $trace) {
                return null;
            }
            $source = $trace['drawing_source'];
            $provenance = 'reviewed-exact-raster-trace';
        }
        try {
            $drawing = $this->drawings->validate($source);
        } catch (ValidationException) {
            return null;
        }
        if ($drawing === null) {
            return null;
        }

        return [
            'drawing_source' => $drawing,
            'source_drawing_sha256' => hash('sha256', json_encode($drawing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'provenance' => $provenance,
            'render_options' => $this->roles()[$imageSha256] ?? [],
        ];
    }

    private function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $path = resource_path('imports/illustration-styles/source-roles.json');
        if (! is_file($path)) {
            return $this->roles = [];
        }
        $pack = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        abort_unless(($pack['format'] ?? null) === 'ribka-native-volume-roles' && ($pack['version'] ?? null) === 1, 500, 'Неверный набор ролей рисунков.');
        $result = [];
        foreach ($pack['items'] ?? [] as $item) {
            $sha = $item['source_sha256'] ?? '';
            abort_unless(is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha) && ! isset($result[$sha]) && is_string($item['review_note'] ?? null), 500, 'Неверная роль исходного рисунка.');
            $options = [];
            if (array_key_exists('diagram', $item)) {
                abort_unless($item['diagram'] === true, 500, 'Неверная роль схемы.');
                $options['diagram'] = true;
            }
            if (array_key_exists('disable_inferred_trunk', $item)) {
                abort_unless($item['disable_inferred_trunk'] === true, 500, 'Неверная настройка объёма корпуса.');
                $options['disable_inferred_trunk'] = true;
            }
            foreach (['ring_element_ids', 'tube_element_ids', 'suppress_fill_element_ids'] as $key) {
                if (! array_key_exists($key, $item)) {
                    continue;
                }
                $ids = $item[$key];
                abort_unless(is_array($ids) && array_is_list($ids) && count($ids) > 0 && count($ids) <= 1000, 500, 'Неверная роль элементов рисунка.');
                foreach ($ids as $id) {
                    abort_unless(is_string($id) && Str::isUuid($id), 500, 'Неверная ссылка на элемент рисунка.');
                }
                abort_unless(count(array_unique($ids)) === count($ids), 500, 'Повтор ссылки на элемент рисунка.');
                $options[$key] = $ids;
            }
            abort_unless($options !== [], 500, 'Не указана роль рисунка.');
            $result[$sha] = $options;
        }

        return $this->roles = $result;
    }

    private function traces(): array
    {
        if ($this->traces !== null) {
            return $this->traces;
        }
        $path = resource_path('imports/illustration-styles/legacy-traces.json');
        if (! is_file($path)) {
            return $this->traces = [];
        }
        $pack = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        abort_unless(($pack['format'] ?? null) === 'ribka-legacy-volume-traces' && ($pack['version'] ?? null) === 1, 500, 'Неверный набор проверенных исходников.');
        $result = [];
        foreach ($pack['traces'] ?? [] as $trace) {
            $sha = $trace['source_sha256'] ?? '';
            abort_unless(is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha) && ! isset($result[$sha]) && isset($trace['drawing_source'], $trace['verification_note'], $trace['author'], $trace['verified_on']), 500, 'Неверное соответствие исходного изображения.');
            $result[$sha] = $trace;
        }

        return $this->traces = $result;
    }
}
