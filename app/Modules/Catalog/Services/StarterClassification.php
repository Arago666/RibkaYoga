<?php

namespace App\Modules\Catalog\Services;

/** A reviewed assignment travels only with its exact seed image and native source. */
class StarterClassification
{
    private ?array $verified = null;

    public function forSource(array $source, string $imagePath, ?array $drawing): ?array
    {
        $verified = $this->verified();
        $item = $verified['items'][$source['id']] ?? null;
        $proof = $verified['provenance'][$source['id']] ?? null;
        if (! $item || ! $proof || ! $drawing || ($item['manual_verified'] ?? false) !== true
            || ($source['workspace_id'] ?? null) !== $verified['workspace_id']
            || ($source['current_image_asset_id'] ?? null) !== ($item['expected_image_asset_id'] ?? null)
            || ($proof['image_asset_id'] ?? null) !== $item['expected_image_asset_id']) {
            return null;
        }
        if (! hash_equals($proof['image_sha256'], hash_file('sha256', $imagePath))
            || ! hash_equals($proof['drawing_sha256'], hash('sha256', json_encode($drawing, JSON_THROW_ON_ERROR)))) {
            return null;
        }
        foreach (Facets::DIMENSIONS as $dimension) {
            foreach ($item['facet_assignments'][$dimension] ?? [] as $key) {
                if (! is_string($key) || ! array_key_exists(substr($key, strlen($dimension) + 1), Facets::VALUES[$dimension]) || ! str_starts_with($key, $dimension.'.')) {
                    return null;
                }
            }
        }

        return $item;
    }

    private function verified(): array
    {
        if ($this->verified !== null) {
            return $this->verified;
        }
        $empty = ['workspace_id' => null, 'items' => [], 'provenance' => []];
        $root = resource_path('imports/catalog-taxonomy');
        $manifestPath = $root.'/verified-20261022.json';
        $proofPath = $root.'/starter-provenance-20261022.json';
        if (! is_file($manifestPath) || ! is_file($proofPath)) {
            return $this->verified = $empty;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents($proofPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 'ribka-classification-v1'
            || ($proof['format'] ?? null) !== 'ribka-starter-provenance-v1'
            || ! hash_equals($proof['manifest_sha256'] ?? '', hash_file('sha256', $manifestPath))) {
            return $this->verified = $empty;
        }

        return $this->verified = ['workspace_id' => $manifest['workspace_id'], 'items' => array_column($manifest['items'], null, 'asana_id'), 'provenance' => array_column($proof['items'], null, 'asana_id')];
    }
}
