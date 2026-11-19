<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        if (! $user->password_login_enabled) {
            throw ValidationException::withMessages(['email' => __('passwords.token')]);
        }

        Validator::make($input, ['password' => ['required', 'confirmed', Password::min(8)]])->validate();
        $user->forceFill(['password' => $input['password']])->save();
    }
}
