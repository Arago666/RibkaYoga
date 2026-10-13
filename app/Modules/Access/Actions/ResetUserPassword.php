<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'confirmed', Password::min(8)]])->validate();
        $user->forceFill(['password' => $input['password']])->save();
    }
}
