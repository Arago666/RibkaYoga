<?php

namespace App\Modules\Access\Console;

use App\Models\User;
use App\Modules\Access\Models\Workspace;
use App\Modules\Access\Models\WorkspaceMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateWorkspace extends Command
{
    use AccountInput;

    protected $signature = 'ribka:workspace {name? : Название пространства} {--email=} {--user= : Имя владельца} {--login= : Логин владельца}';

    protected $description = 'Создать пространство и первого владельца со скрытым вводом пароля';

    public function handle(): int
    {
        $name = $this->argument('name') ?: $this->ask('Название рабочего пространства');
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:160']])->validate();
        $account = $this->readAccount();
        $workspace = DB::transaction(function () use ($name, $account) {
            $user = User::create($account);
            $workspace = Workspace::create(['name' => $name, 'created_by' => $user->id]);
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);

            return $workspace;
        });
        $this->info('Пространство создано: '.$workspace->id);
        $this->info('Владелец: '.$account['email']);
        $this->info('Логин: '.$account['login']);

        return self::SUCCESS;
    }
}
