<?php

namespace App\Modules\Billing\PublicApi;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionAccess
{
    /** Restore legacy workspace access without overwriting any existing subscription. */
    public function ensureLegacyGrant(string $workspaceId): void
    {
        Subscription::firstOrCreate(['workspace_id' => $workspaceId], [
            'plan' => 'pro', 'status' => 'active', 'starts_at' => now(), 'ends_at' => null,
            'cancel_at_period_end' => true,
        ]);
    }

    public function describe(string $workspaceId): array
    {
        $subscription = Subscription::where('workspace_id', $workspaceId)->first();
        $status = $subscription?->status ?? 'basic';
        if ($subscription?->ends_at && $subscription->ends_at->isPast()) {
            $status = 'expired';
        }
        $active = in_array($status, ['active', 'trialing'], true);
        $plan = $active ? $subscription->plan : 'basic';

        return [
            'id' => $subscription?->id, 'plan' => $plan, 'plan_name' => config('billing.plans.'.$plan.'.name'),
            'status' => $status, 'ends_at' => $subscription?->ends_at?->toIso8601String(),
            'cancel_at_period_end' => $subscription?->cancel_at_period_end ?? true,
            'revision' => $subscription?->revision ?? 0,
            'limits' => app(Entitlements::class)->limits($workspaceId),
            'payment_provider_connected' => false,
            'payments' => DB::table('billing_payments')->where('workspace_id', $workspaceId)
                ->orderByDesc('created_at')->limit(30)->get(['id', 'status', 'amount_minor', 'currency', 'paid_at']),
        ];
    }

    public function startTrial(string $workspaceId): void
    {
        $days = max(0, min(365, (int) config('billing.trial_days', 14)));
        Subscription::create([
            'workspace_id' => $workspaceId, 'plan' => $days ? 'pro' : 'basic', 'status' => $days ? 'trialing' : 'active',
            'starts_at' => now(), 'ends_at' => $days ? now()->addDays($days) : null, 'cancel_at_period_end' => true,
        ]);
        SubscriptionEvent::create(['workspace_id' => $workspaceId, 'action' => 'registration_trial', 'details' => ['days' => $days]]);
    }

    public function grant(string $workspaceId, string $plan, ?int $days, int $actorId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($workspaceId, $plan, $days, $actorId, $expectedRevision) {
            $subscription = Subscription::where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (($subscription?->revision ?? 0) !== $expectedRevision) {
                abort(409, 'Подписка изменилась. Обновите страницу перед изменением доступа.');
            }
            if (! array_key_exists($plan, config('billing.plans')) || $days !== null && ($days < 1 || $days > 3650)) {
                throw ValidationException::withMessages(['plan' => 'Укажите корректный тариф и срок от 1 до 3650 дней.']);
            }
            $values = ['plan' => $plan, 'status' => 'active', 'starts_at' => now(), 'ends_at' => $days ? now()->addDays($days) : null,
                'cancel_at_period_end' => true, 'revision' => ($subscription?->revision ?? 0) + 1];
            if ($subscription) {
                $subscription->update($values);
            } else {
                Subscription::create(['workspace_id' => $workspaceId, ...$values]);
            }
            SubscriptionEvent::create(['workspace_id' => $workspaceId, 'actor_id' => $actorId, 'action' => 'manual_grant', 'details' => ['plan' => $plan, 'days' => $days]]);

            return $this->describe($workspaceId);
        });
    }

    public function cancelRenewal(string $workspaceId, int $actorId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $expectedRevision) {
            $subscription = Subscription::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_if($subscription->revision !== $expectedRevision, 409, 'Подписка изменилась. Обновите страницу.');
            if (! $subscription->cancel_at_period_end) {
                $subscription->update(['cancel_at_period_end' => true, 'revision' => $subscription->revision + 1]);
                SubscriptionEvent::create(['workspace_id' => $workspaceId, 'actor_id' => $actorId, 'action' => 'cancel_renewal', 'details' => []]);
            }

            return $this->describe($workspaceId);
        });
    }

    public function summary(): array
    {
        $subscriptions = Subscription::query()->get();

        return [
            'active_spaces' => $subscriptions->filter(fn ($item) => in_array($item->status, ['active', 'trialing'], true) && (! $item->ends_at || $item->ends_at->isFuture()))->count(),
            'trial_spaces' => $subscriptions->filter(fn ($item) => $item->status === 'trialing' && $item->ends_at?->isFuture())->count(),
            'expired_spaces' => $subscriptions->filter(fn ($item) => $item->ends_at?->isPast())->count(),
            'paid_count' => DB::table('billing_payments')->where('status', 'paid')->where('currency', 'RUB')->count(),
            'revenue_minor' => (int) DB::table('billing_payments')->where('status', 'paid')->where('currency', 'RUB')->sum('amount_minor'),
            'payment_provider_connected' => false,
        ];
    }
}
