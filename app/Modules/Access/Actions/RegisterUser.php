<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class RegisterUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $input['login'] = mb_strtolower(trim((string) ($input['login'] ?? '')));
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'login' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/', 'unique:users,login'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ])->validate();

        return DB::transaction(function () use ($data) {
            $user = User::create(['name' => trim($data['name']), 'email' => $data['email'], 'login' => $data['login'], 'password' => $data['password']]);
            app(CreatePersonalWorkspace::class)->create($user);

            return $user;
        });
    }
}
