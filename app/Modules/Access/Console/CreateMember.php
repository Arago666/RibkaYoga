<?php

namespace App\Modules\Access\Console;

use App\Models\User;
use App\Modules\Access\Models\Workspace;
use App\Modules\Access\Models\WorkspaceMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateMember extends Command
{
    use AccountInput;

    protected $signature = 'ribka:member {workspace : UUID пространства} {--email=} {--user= : Имя участника} {--login= : Логин участника} {--role=editor}';

    protected $description = 'Добавить отдельный аккаунт преподавателя или владельца в пространство';

    public function handle(): int
    {
        $workspace = Workspace::findOrFail($this->argument('workspace'));
        $role = $this->option('role');
        Validator::make(['role' => $role], ['role' => [Rule::in(['owner', 'editor'])]])->validate();
        $account = $this->readAccount();
        DB::transaction(function () use ($workspace, $role, $account) {
            $user = User::create($account);
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
        });
        $this->info('Участник добавлен: '.$account['email']);
        $this->info('Логин: '.$account['login']);

        return self::SUCCESS;
    }
}
