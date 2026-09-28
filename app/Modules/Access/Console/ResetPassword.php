<?php

namespace App\Modules\Access\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetPassword extends Command
{
    use AccountInput;

    protected $signature = 'ribka:password {email : Email аккаунта}';

    protected $description = 'Сбросить пароль аккаунта без почтового сервиса и завершить его активные сеансы';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower($this->argument('email')))->firstOrFail();
        $password = $this->readPassword();
        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        $this->info('Пароль обновлён. Активные сеансы завершены.');

        return self::SUCCESS;
    }
}
