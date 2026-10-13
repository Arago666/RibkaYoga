<?php

namespace App\Modules\Access\Console;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

trait AccountInput
{
    private function readAccount(): array
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $name = trim((string) ($this->option('user') ?: $this->ask('Имя')));
        $login = mb_strtolower(trim((string) ($this->option('login') ?: $this->ask('Логин'))));

        return Validator::make(['email' => $email, 'name' => $name, 'login' => $login, 'password' => $this->readPassword()], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:120'],
            'login' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/', 'unique:users,login'],
            'password' => ['required', Password::min(12)],
        ])->validate();
    }

    private function readPassword(): string
    {
        $environmentPassword = getenv('RIBKA_INITIAL_PASSWORD');
        if (is_string($environmentPassword) && $environmentPassword !== '') {
            $password = $environmentPassword;
        } else {
            $password = (string) $this->secret('Пароль (не менее 12 символов)');
            if ($password !== (string) $this->secret('Повторите пароль')) {
                throw new \InvalidArgumentException('Пароли не совпадают.');
            }
        }
        Validator::make(['password' => $password], ['password' => ['required', Password::min(12)]])->validate();

        return $password;
    }
}
