<?php

namespace App\Modules\Access\Http;

use App\Modules\Access\Models\Workspace;
use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Billing\PublicApi\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AccountController
{
    public function __construct(private WorkspaceContext $workspace, private SubscriptionAccess $subscription) {}

    public function page()
    {
        return Inertia::render('access/Account');
    }

    public function show(Request $request): array
    {
        $user = $request->user();

        return [
            'user' => [...$user->only('id', 'name', 'login', 'email', 'phone', 'telegram', 'requisites', 'is_admin'),
                'avatar_url' => $user->avatar_path ? '/account/avatar?v='.$user->updated_at->timestamp : null],
            'workspace' => $this->workspace->details(), 'subscription' => $this->subscription->describe($this->workspace->id()),
            'plans' => config('billing.plans'),
        ];
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $request->merge(['login' => mb_strtolower(trim((string) $request->input('login'))), 'email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'login' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/', Rule::unique('users', 'login')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:64'], 'telegram' => ['nullable', 'string', 'max:120'],
            'requisites' => ['nullable', 'string', 'max:3000'], 'workspace_name' => ['required', 'string', 'max:160'],
            'avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'], 'remove_avatar' => ['sometimes', 'boolean'],
            'profile_password' => ['nullable', Rule::requiredIf($user->email !== $request->input('email') || $user->login !== $request->input('login')), 'current_password'],
        ]);
        $newPath = null;
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 4000000) {
                throw ValidationException::withMessages(['avatar' => 'Аватар должен быть не больше 4 мегапикселей.']);
            }
            $newPath = Storage::disk('local')->putFileAs('avatars/'.$user->id, $file, Str::uuid().'.'.$file->extension());
            if (! $newPath) {
                throw ValidationException::withMessages(['avatar' => 'Не удалось сохранить аватар.']);
            }
        }
        try {
            DB::transaction(function () use ($user, $data, $newPath) {
                $changes = array_intersect_key($data, array_flip(['name', 'login', 'email', 'phone', 'telegram', 'requisites']));
                $changes['name'] = trim($changes['name']);
                if ($user->email !== $changes['email']) {
                    $changes['email_verified_at'] = null;
                }
                if ($newPath || ($data['remove_avatar'] ?? false)) {
                    $changes['avatar_path'] = $newPath;
                }
                $user->forceFill($changes)->save();
                if ($this->workspace->details()['role'] === 'owner') {
                    Workspace::whereKey($this->workspace->id())->update(['name' => trim($data['workspace_name'])]);
                }
            });
        } catch (\Throwable $failure) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $failure;
        }

        return redirect()->route('account')->with('success', 'Профиль сохранён.');
    }

    public function password(Request $request): array
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::min(8)]]);
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();

        return ['message' => 'Пароль изменён.'];
    }

    public function avatar(Request $request)
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Str::startsWith($path, 'avatars/'.$request->user()->id.'/') && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }

    public function cancelRenewal(Request $request): array
    {
        abort_unless($this->workspace->details()['role'] === 'owner', 403, 'Только владелец может изменить подписку.');
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        return $this->subscription->cancelRenewal($this->workspace->id(), $request->user()->id, $data['expected_revision']);
    }
}
