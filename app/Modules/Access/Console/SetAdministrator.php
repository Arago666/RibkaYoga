<?php

namespace App\Modules\Access\Console;

use App\Models\User;
use Illuminate\Console\Command;

class SetAdministrator extends Command
{
    protected $signature = 'ribka:admin {login : Логин пользователя} {--revoke : Снять права администратора}';

    protected $description = 'Явно назначить или снять администратора приложения';

    public function handle(): int
    {
        $user = User::where('login', mb_strtolower(trim((string) $this->argument('login'))))->first();
        if (! $user) {
            $this->error('Пользователь не найден.');

            return self::FAILURE;
        }
        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? 'Права администратора сняты.' : 'Права администратора назначены: '.$user->login);

        return self::SUCCESS;
    }
}
