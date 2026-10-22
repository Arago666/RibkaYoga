<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Services\Facets;
use App\Modules\Catalog\Services\Names;
use Illuminate\Console\Command;

class SeedTaxonomy extends Command
{
    protected $signature = 'ribka:seed-taxonomy {--apply : Создать недостающие 45 значений и обновить производный поисковый индекс}';

    protected $description = 'Идемпотентно создать системный словарь существующих пространств без классификации асан';

    public function handle(Facets $facets): int
    {
        $workspaces = Asana::query()->distinct()->pluck('workspace_id');
        if ($this->option('apply')) {
            foreach ($workspaces as $workspaceId) {
                $facets->ensure($workspaceId);
            }
            Asana::with('aliases')->chunkById(200, function ($cards) {
                foreach ($cards as $card) {
                    Asana::where('id', $card->id)->where('revision', $card->revision)->toBase()->update(['normalized_name' => Names::normalize($card->name), 'normalized_search_text' => Names::normalize(implode(' ', [$card->name, $card->sanskrit_cyrillic ?? '', $card->sanskrit_latin ?? '', ...$card->aliases->pluck('name')->all()]))]);
                }
            });
        }
        $this->info(($this->option('apply') ? 'Словарь создан; имена и ревизии карточек сохранены. ' : 'Предпросмотр без записи. ').'Пространств с каталогом: '.$workspaces->count().'. Классификация не назначается.');

        return self::SUCCESS;
    }
}
