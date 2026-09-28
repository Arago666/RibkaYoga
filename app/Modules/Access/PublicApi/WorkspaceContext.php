<?php

namespace App\Modules\Access\PublicApi;

use App\Modules\Access\Models\Workspace;
use App\Modules\Access\Models\WorkspaceMember;
use Illuminate\Support\Facades\Auth;

/** Membership is resolved exclusively from the authenticated session. */
class WorkspaceContext
{
    public function userId(): int
    {
        abort_unless(Auth::check(), 401);

        return (int) Auth::id();
    }

    public function id(): string
    {
        return $this->membership()->workspace_id;
    }

    public function details(): array
    {
        $member = $this->membership();
        $workspace = Workspace::findOrFail($member->workspace_id);

        return ['id' => $workspace->id, 'name' => $workspace->name, 'role' => $member->role];
    }

    private function membership(): WorkspaceMember
    {
        $member = WorkspaceMember::where('user_id', $this->userId())->first();
        abort_unless($member && in_array($member->role, ['owner', 'editor'], true), 403, 'Нет доступа к рабочему пространству.');

        return $member;
    }
}
