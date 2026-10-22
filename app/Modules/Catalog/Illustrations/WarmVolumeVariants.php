<?php

namespace App\Modules\Catalog\Illustrations;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\PublicApi\IllustrationStyles;
use App\Modules\Media\PublicApi\AssetFingerprints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WarmVolumeVariants extends Command
{
    protected $signature = 'ribka:warm-volume-variants
        {--workspace= : UUID рабочего пространства}
        {--owner= : Точный логин или email владельца пространства}
        {--include-archived : Подготовить также архивные карточки без их восстановления}
        {--character=* : neutral, female, male; по умолчанию все три}
        {--apply : Создать недостающие варианты; по умолчанию только проверка}
        {--report= : Новый приватный JSON-файл с подробным результатом}';

    protected $description = 'Проверить и прогреть объёмные варианты точных исходников без изменения карточек и практик';

    public function handle(WorkspaceContext $workspace, AssetFingerprints $fingerprints, NativeVolumeSources $sources, IllustrationStyles $styles): int
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $report = null;
        try {
            $characters = $this->option('character') ?: ['neutral', 'female', 'male'];
            $options = Validator::make([
                'workspace' => $this->option('workspace'), 'owner' => $this->option('owner'),
                'characters' => $characters, 'report' => $this->option('report'),
            ], [
                'workspace' => ['required', 'uuid'], 'owner' => ['required', 'string', 'max:255'],
                'characters' => ['required', 'array', 'list', 'min:1', 'max:3'],
                'characters.*' => ['required', 'string', 'distinct:strict', Rule::in(['neutral', 'female', 'male'])],
                'report' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $identity = trim($options['owner']);
            $owners = User::where(fn ($query) => $query->where('login', mb_strtolower($identity))->orWhere('email', $identity))->limit(2)->get();
            abort_unless($identity !== '' && $owners->count() === 1, 422, 'Укажите однозначный точный логин или email существующего владельца.');
            $guard->setUser($owners->first());
            $access = $workspace->details();
            abort_unless($access['role'] === 'owner' && $access['id'] === strtolower($options['workspace']), 403, 'Аккаунт должен быть владельцем указанного пространства.');
            $report = $this->openReport($options['report'] ?? null);

            [$summary, $cards] = $this->runForWorkspace($workspace, $options['characters'], $fingerprints, $sources, $styles);
            if (is_resource($report)) {
                $json = json_encode(['summary' => $summary, 'cards' => $cards], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
                abort_unless(fwrite($report, $json) === strlen($json) && fflush($report), 500, 'Не удалось полностью записать приватный отчёт.');
            }
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $summary['apply_requested']) {
                $this->info('Предпросмотр: база и изображения не изменены. Для записи используйте --apply.');
            }

            return $summary['errors'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            $this->error($this->errorMessage($error));

            return self::FAILURE;
        } finally {
            if (is_resource($report)) {
                fclose($report);
            }
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    private function runForWorkspace(WorkspaceContext $workspace, array $characters, AssetFingerprints $fingerprints, NativeVolumeSources $sources, IllustrationStyles $styles): array
    {
        $query = Asana::where('workspace_id', $workspace->id())->orderBy('id');
        if (! $this->option('include-archived')) {
            $query->whereNull('archived_at');
        }
        $cards = $query->get(['id', 'name', 'current_image_asset_id', 'drawing_source', 'archived_at']);
        $existing = DB::table('catalog_illustration_variants')->where('workspace_id', $workspace->id())
            ->whereIn('asana_id', $cards->modelKeys())->where('style', 'volume')->whereIn('character', $characters)
            ->where('model_revision', NativeVolumeRenderer::MODEL_REVISION)->get()->keyBy(fn ($row) => $this->key($row->asana_id, $row->source_asset_id, $row->character));
        $summary = [
            'workspace_id' => $workspace->id(), 'owner_id' => $workspace->userId(),
            'apply_requested' => (bool) $this->option('apply'), 'applied' => false, 'include_archived' => (bool) $this->option('include-archived'),
            'model_revision' => NativeVolumeRenderer::MODEL_REVISION, 'characters' => $characters,
            'cards' => $cards->count(), 'active_cards' => $cards->whereNull('archived_at')->count(),
            'archived_cards' => $cards->whereNotNull('archived_at')->count(), 'eligible_cards' => 0,
            'expected_variants' => $cards->count() * count($characters), 'existing_variants' => 0,
            'missing_variants_before' => 0, 'missing_variants' => 0, 'created_variants' => 0, 'reused_variants' => 0,
            'prepared_variants' => 0, 'failed_variants' => 0, 'errors' => 0, 'all_variants_ready' => false,
        ];
        $results = [];
        foreach ($cards as $index => $card) {
            $result = ['asana_id' => $card->id, 'name' => $card->name, 'archived' => $card->archived_at !== null, 'source_asset_id' => $card->current_image_asset_id, 'eligible' => false, 'existing_variants' => [], 'prepared_variants' => [], 'errors' => []];
            try {
                $sha = $fingerprints->forMany([$card->current_image_asset_id])[$card->current_image_asset_id];
                $source = $sources->resolve($card->drawing_source, $sha);
                abort_unless($source !== null, 422, 'Для точного исходного изображения нет допустимой геометрии.');
                $result += ['source_sha256' => $sha, 'source_drawing_sha256' => $source['source_drawing_sha256'], 'provenance' => $source['provenance']];
                foreach ($characters as $character) {
                    $variant = $existing->get($this->key($card->id, $card->current_image_asset_id, $character));
                    if (! $variant) {
                        continue;
                    }
                    $render = json_decode($variant->render_source, true, 64, JSON_THROW_ON_ERROR);
                    abort_unless($variant->source_sha256 === $sha && ($render['source_sha256'] ?? null) === $sha && ($render['source_drawing_sha256'] ?? null) === $source['source_drawing_sha256'] && ($render['render_options'] ?? []) === $source['render_options'] && $variant->image_asset_id !== $card->current_image_asset_id, 409, 'Сохранённый вариант не соответствует точной геометрии исходника.');
                    $imageSha = $fingerprints->forMany([$variant->image_asset_id])[$variant->image_asset_id];
                    $result['existing_variants'][$character] = ['variant_id' => $variant->id, 'image_asset_id' => $variant->image_asset_id, 'image_sha256' => $imageSha];
                }
                $result['eligible'] = true;
                $summary['eligible_cards']++;
                $summary['existing_variants'] += count($result['existing_variants']);
            } catch (\Throwable $error) {
                $result['errors'][] = $this->errorMessage($error);
                $summary['errors']++;
                $this->error($card->id.': '.end($result['errors']));
            }
            $results[] = $result;
            if (($index + 1) % 25 === 0 || $index + 1 === $cards->count()) {
                $this->line('Проверено исходников: '.($index + 1).'/'.$cards->count().'.');
            }
        }
        $summary['missing_variants'] = $summary['expected_variants'] - $summary['existing_variants'];
        $summary['missing_variants_before'] = $summary['missing_variants'];
        if ($summary['errors'] > 0) {
            $this->error('Проверка неполная. Запись вариантов не начата.');

            return [$summary, $results];
        }
        if (! $summary['apply_requested']) {
            $summary['all_variants_ready'] = $summary['missing_variants'] === 0;

            return [$summary, $results];
        }

        $summary['applied'] = true;
        foreach ($results as $index => &$result) {
            foreach ($characters as $character) {
                try {
                    $prepared = $styles->warmVolume($result['asana_id'], $result['source_asset_id'], $character);
                    abort_unless(($prepared['available'] ?? false) === true && ($prepared['fallback'] ?? true) === false
                        && ($prepared['style'] ?? null) === 'volume' && ($prepared['character'] ?? null) === $character
                        && ($prepared['asana_id'] ?? null) === $result['asana_id'] && ($prepared['source_asset_id'] ?? null) === $result['source_asset_id']
                        && ($prepared['source_sha256'] ?? null) === $result['source_sha256']
                        && ($prepared['render_source']['format'] ?? null) === 'ribka-native-volume'
                        && ($prepared['render_source']['model_revision'] ?? null) === NativeVolumeRenderer::MODEL_REVISION
                        && ($prepared['render_source']['source_drawing_sha256'] ?? null) === $result['source_drawing_sha256']
                        && Str::isUuid($prepared['variant_id'] ?? '') && Str::isUuid($prepared['image_asset_id'] ?? '')
                        && $prepared['image_asset_id'] !== $result['source_asset_id'], 500, 'Подготовка не вернула точный отдельный объёмный вариант.');
                    $imageSha = $fingerprints->forMany([$prepared['image_asset_id']])[$prepared['image_asset_id']];
                    $result['prepared_variants'][$character] = ['variant_id' => $prepared['variant_id'], 'image_asset_id' => $prepared['image_asset_id'], 'image_sha256' => $imageSha];
                    $summary['prepared_variants']++;
                    if (($result['existing_variants'][$character]['variant_id'] ?? null) === $prepared['variant_id']) {
                        $summary['reused_variants']++;
                    } else {
                        $summary['created_variants']++;
                    }
                } catch (\Throwable $error) {
                    $result['errors'][] = $character.': '.$this->errorMessage($error);
                    $summary['errors']++;
                    $summary['failed_variants']++;
                    $this->error($result['asana_id'].' / '.end($result['errors']));
                }
            }
            if (($index + 1) % 25 === 0 || $index + 1 === count($results)) {
                $this->line('Обработано карточек: '.($index + 1).'/'.count($results).'; готово вариантов: '.$summary['prepared_variants'].'.');
            }
        }
        unset($result);
        $summary['missing_variants'] = $summary['expected_variants'] - $summary['prepared_variants'];
        $summary['all_variants_ready'] = $summary['errors'] === 0 && $summary['prepared_variants'] === $summary['expected_variants'];

        return [$summary, $results];
    }

    private function key(string $asanaId, string $sourceId, string $character): string
    {
        return $asanaId.'|'.$sourceId.'|'.$character;
    }

    /** The requested file is created exclusively and kept private even during a long prewarm. */
    private function openReport(?string $path): mixed
    {
        if ($path === null) {
            return null;
        }
        abort_unless(! str_contains($path, "\0") && ! str_contains($path, '://') && str_ends_with(strtolower($path), '.json'), 422, 'Отчёт должен быть новым локальным JSON-файлом.');
        $directory = realpath(dirname($path));
        $public = realpath(public_path());
        abort_unless($directory && is_writable($directory) && (! $public || ($directory !== $public && ! str_starts_with($directory, $public.DIRECTORY_SEPARATOR))), 422, 'Каталог приватного отчёта недоступен или находится в public.');
        $target = $directory.DIRECTORY_SEPARATOR.basename($path);
        $handle = @fopen($target, 'x+b');
        abort_unless(is_resource($handle), 422, 'Не удалось создать новый отчёт. Существующий файл не перезаписывается.');
        if (! chmod($target, 0600)) {
            fclose($handle);
            @unlink($target);
            abort(500, 'Не удалось ограничить доступ к приватному отчёту.');
        }

        return $handle;
    }

    private function errorMessage(\Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return implode(' ', $error->validator->errors()->all());
        }
        if ($error instanceof HttpExceptionInterface && $error->getMessage() !== '') {
            return $error->getMessage();
        }
        report($error);

        return 'Не удалось завершить операцию; подробности в приватном журнале Laravel.';
    }
}
