<?php

namespace App\Http\Middleware;

use App\Modules\Access\PublicApi\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'csrf_token' => csrf_token(),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'login', 'is_admin'),
                'workspace' => $request->user() ? app(WorkspaceContext::class)->details() : null,
            ],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
