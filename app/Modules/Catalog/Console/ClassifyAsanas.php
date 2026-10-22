<?php

namespace App\Modules\Catalog\Console;

use App\Models\User;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Catalog\Services\Classification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ClassifyAsanas extends Command
{
    protected $signature = 'ribka:classify-asanas {manifest : Проверенный JSON ribka-classification-v1} {--workspace=} {--owner=} {--dry-run : Проверить без записи (режим по умолчанию)} {--apply : Применить после проверки ревизий; по умолчанию только предпросмотр}';

    protected $description = 'Проверить и атомарно применить ручную разметку каталога без замены рисунков';

    public function handle(WorkspaceContext $workspace, Classification $classification): int
    {
        $path = realpath((string) $this->argument('manifest'));
        if (! $path || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Нужен локальный JSON до 4 МБ.']);
        }
        abort_if($this->option('apply') && $this->option('dry-run'), 422, 'Выберите только один режим: --apply или --dry-run.');
        $owner = User::where('email', $this->option('owner'))->firstOrFail();
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($owner);
        try {
            $access = $workspace->details();
            abort_unless($access['role'] === 'owner' && $access['id'] === $this->option('workspace'), 403);
            $result = $classification->run(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
