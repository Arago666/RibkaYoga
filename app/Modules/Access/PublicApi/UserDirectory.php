<?php

namespace App\Modules\Access\PublicApi;

use App\Models\User;

/** Small identity DTOs; never returns email, password, billing details or roles. */
class UserDirectory
{
    public function findByLogin(string $login): ?array
    {
        $user = User::where('login', mb_strtolower(trim($login)))->first(['id', 'name', 'login']);

        return $user ? $user->only(['id', 'name', 'login']) : null;
    }

    public function findMany(array $ids): array
    {
        return User::whereIn('id', $ids)->get(['id', 'name', 'login'])->mapWithKeys(fn ($user) => [
            $user->id => $user->only(['id', 'name', 'login']),
        ])->all();
    }
}
