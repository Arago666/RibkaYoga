<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use App\Modules\Access\Models\Workspace;
use App\Modules\Access\Models\WorkspaceMember;
use App\Modules\Billing\PublicApi\SubscriptionAccess;
use App\Modules\Catalog\PublicApi\StarterLibrary;
use Illuminate\Support\Facades\Auth;
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
            $workspace = Workspace::create(['name' => 'Практики '.$user->name, 'created_by' => $user->id]);
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
            app(SubscriptionAccess::class)->startTrial($workspace->id);
            // Media contracts may resolve the authenticated workspace while making starter copies.
            $previousUser = Auth::user();
            Auth::setUser($user);
            try {
                app(StarterLibrary::class)->copyToWorkspace($workspace->id);
            } finally {
                $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
            }

            return $user;
        });
    }
}
