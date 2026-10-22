<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Services\DrawingSource;
use App\Modules\Media\PublicApi\AssetStore;
use App\Modules\Practices\PublicApi\NativeImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PinNativeSources extends Command
{
    protected $signature = 'ribka:pin-native {--workspace=} {--owner=} {--dry-run : Проверить без записи (режим по умолчанию)} {--apply : Закрепить исходники; по умолчанию только чтение и проверка}';

    protected $description = 'Закрепить точные исходники текущих изображений, включая архив, без изменения карточек и снимков';

    public function handle(WorkspaceContext $workspace, DrawingSource $validator, AssetStore $assets, NativeImages $native): int
    {
        abort_if($this->option('apply') && $this->option('dry-run'), 422, 'Выберите только один режим: --apply или --dry-run.');
        $owner = User::where('email', $this->option('owner'))->firstOrFail();
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($owner);
        try {
            $access = $workspace->details();
            abort_unless($access['role'] === 'owner' && $access['id'] === $this->option('workspace'), 403);
            $result = DB::transaction(function () use ($workspace, $validator, $assets, $native) {
                $cards = Asana::where('workspace_id', $workspace->id())->whereNotNull('drawing_source')->orderBy('id')->sharedLock()->get();
                $seen = [];
                $counts = ['cards' => $cards->count(), 'archived_cards' => 0, 'existing_images' => 0, 'new_images' => 0, 'applied' => (bool) $this->option('apply')];
                foreach ($cards as $card) {
                    $drawing = $validator->validate($card->drawing_source);
                    $counts['archived_cards'] += $card->archived_at ? 1 : 0;
                    if (isset($seen[$card->current_image_asset_id])) {
                        abort_unless($seen[$card->current_image_asset_id] === $drawing, 409, 'Одному изображению соответствуют разные исходники; нужна ручная проверка.');

                        continue;
                    }
                    $seen[$card->current_image_asset_id] = $drawing;
                    $original = $assets->originalData($card->current_image_asset_id);
                    abort_unless(! empty($original['data_url']), 422, 'Оригинал изображения недоступен.');
                    $existing = $native->source($card->current_image_asset_id);
                    if ($existing !== null) {
                        abort_unless($existing === $drawing, 409, 'Для изображения уже сохранён другой исходник.');
                        $counts['existing_images']++;
                    } else {
                        $counts['new_images']++;
                    }
                    if ($this->option('apply')) {
                        $native->remember($card->current_image_asset_id, $drawing);
                    }
                }

                return $counts;
            });
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (! $this->option('apply')) {
                $this->info('Данные не изменены. Форматы, файлы и совпадение исходников проверены; сохранённый SHA проверяется при применении.');
            }

            return self::SUCCESS;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
