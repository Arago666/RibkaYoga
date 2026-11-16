<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use App\Modules\Access\Models\Workspace;
use App\Modules\Access\Models\WorkspaceMember;
use App\Modules\Billing\PublicApi\SubscriptionAccess;
use App\Modules\Catalog\PublicApi\StarterLibrary;
use Illuminate\Support\Facades\Auth;

class CreatePersonalWorkspace
{
    /** Called inside the account creation transaction. */
    public function create(User $user): void
    {
        $workspace = Workspace::create(['name' => 'Практики '.$user->name, 'created_by' => $user->id]);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        app(SubscriptionAccess::class)->startTrial($workspace->id);
        $previousUser = Auth::user();
        Auth::setUser($user);
        try {
            app(StarterLibrary::class)->copyToWorkspace($workspace->id);
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }
    }
}
