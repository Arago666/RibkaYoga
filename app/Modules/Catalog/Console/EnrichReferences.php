<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Services\ReferenceEnrichment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EnrichReferences extends Command
{
    protected $signature = 'ribka:enrich-references {manifest : Проверенный JSON ribka-reference-v1} {--workspace=} {--owner=} {--dry-run : Проверить без записи (режим по умолчанию)} {--apply : Атомарно дополнить пустую справку после сверки ревизий и изображений}';

    protected $description = 'Внести собственные визуальные описания без замены рисунков и заполненной авторской справки';

    public function handle(WorkspaceContext $workspace, ReferenceEnrichment $enrichment): int
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Нужен локальный JSON до 4 МБ.']);
        }
        abort_if($this->option('apply') && $this->option('dry-run'), 422, 'Выберите только один режим: --apply или --dry-run.');
        $identity = mb_strtolower(trim((string) $this->option('owner')));
        $matches = User::where(fn ($query) => $query->whereRaw('lower(email) = ?', [$identity])->orWhereRaw('lower(login) = ?', [$identity]))->limit(2)->get();
        abort_unless($identity !== '' && $matches->count() === 1, 422, 'Укажите однозначный логин или email владельца.');
        $owner = $matches->first();
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($owner);
        try {
            $access = $workspace->details();
            abort_unless($access['role'] === 'owner' && $access['id'] === $this->option('workspace'), 403);
            $result = $enrichment->run(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
