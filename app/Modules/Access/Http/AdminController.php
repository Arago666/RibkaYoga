<?php

namespace App\Modules\Access\Http;

use App\Models\User;
use App\Modules\Access\Models\WorkspaceMember;
use App\Modules\Billing\PublicApi\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminController
{
    public function __construct(private SubscriptionAccess $subscriptions) {}

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->is_admin, 403, 'Доступ разрешён только администратору.');
    }

    public function page(Request $request)
    {
        $this->authorize($request);

        return Inertia::render('access/Admin');
    }

    public function index(Request $request): array
    {
        $this->authorize($request);
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 120);
        $query = User::query();
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('name', 'like', '%'.$search.'%')->orWhere('login', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            });
        }
        $page = $query->orderByDesc('id')->paginate(30);
        $memberships = WorkspaceMember::whereIn('user_id', $page->getCollection()->pluck('id'))->get()->keyBy('user_id');
        $page->setCollection($page->getCollection()->map(function ($user) use ($memberships) {
            $membership = $memberships->get($user->id);

            return [...$user->only('id', 'name', 'login', 'email', 'is_admin', 'created_at'),
                'workspace_id' => $membership?->workspace_id, 'role' => $membership?->role,
                'subscription' => $membership ? $this->subscriptions->describe($membership->workspace_id) : null];
        }));

        return ['users' => $page->toArray(), 'summary' => ['users' => User::count(), ...$this->subscriptions->summary()]];
    }

    public function grant(Request $request, int $userId): array
    {
        $this->authorize($request);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('billing.plans')))],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'expected_revision' => ['required', 'integer', 'min:0']]);
        $membership = WorkspaceMember::where('user_id', $userId)->firstOrFail();

        return $this->subscriptions->grant($membership->workspace_id, $data['plan'], $data['days'] ?? null, $request->user()->id, $data['expected_revision']);
    }

    public function export(Request $request)
    {
        $this->authorize($request);
        $rows = User::query()->orderBy('id')->get()->map(function ($user) {
            $membership = WorkspaceMember::where('user_id', $user->id)->first();

            return [...$user->only('id', 'name', 'login', 'email', 'created_at'), 'workspace_id' => $membership?->workspace_id,
                'subscription' => $membership ? $this->subscriptions->describe($membership->workspace_id) : null];
        });

        return response()->json(['format' => 'ribka-account-report', 'version' => 1, 'exported_at' => now()->toIso8601String(),
            'summary' => ['users' => User::count(), ...$this->subscriptions->summary()], 'users' => $rows], 200, ['Content-Disposition' => 'attachment; filename="ribka-accounts.json"']);
    }
}
