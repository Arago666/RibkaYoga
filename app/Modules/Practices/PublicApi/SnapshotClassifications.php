<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Catalog\PublicApi\PoseFacts;
use Illuminate\Support\Facades\Validator;

/** Lightweight facts belong to one immutable practice snapshot, without a catalog identity. */
class SnapshotClassifications
{
    public function __construct(private PoseFacts $facts) {}

    public function capture(string $asanaId, string $imageAssetId): ?array
    {
        $facts = $this->facts->forImages([['asana_id' => $asanaId, 'image_asset_id' => $imageAssetId]]);

        return $this->validate($facts[$imageAssetId] ?? null);
    }

    public function validate(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $data = Validator::make(['classification_snapshot' => $value], [
            'classification_snapshot' => ['required', 'array:revision,anatomy,difficulty'],
            'classification_snapshot.revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'classification_snapshot.anatomy' => ['present', 'array', 'list', 'max:15'],
            'classification_snapshot.anatomy.*' => ['required', 'array:key,name'],
            'classification_snapshot.anatomy.*.key' => ['required', 'string', 'max:80', 'regex:/^anatomy\.[a-z_]+$/D', 'distinct:ignore_case'],
            'classification_snapshot.anatomy.*.name' => ['required', 'string', 'max:160'],
            'classification_snapshot.difficulty' => ['present', 'nullable', 'string', 'max:160'],
        ])->validate()['classification_snapshot'];
        usort($data['anatomy'], fn ($a, $b) => strcmp($a['key'], $b['key']));

        return ['revision' => (int) $data['revision'], 'anatomy' => $data['anatomy'], 'difficulty' => $data['difficulty']];
    }

    public function fromStored(mixed $value): ?array
    {
        return $this->validate(is_string($value) ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : $value);
    }

    public function encode(?array $value): ?string
    {
        return $value === null ? null : json_encode($this->validate($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function sameFacts(array $before, array $after): bool
    {
        $before = $this->validate($before);
        $after = $this->validate($after);

        return $before['anatomy'] === $after['anatomy'] && $before['difficulty'] === $after['difficulty'];
    }
}
