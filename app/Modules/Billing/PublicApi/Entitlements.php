<?php

namespace App\Modules\Billing\PublicApi;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Validation\ValidationException;

class Entitlements
{
    /** Call within a transaction before counting or creating quota-limited objects. */
    public function lock(string $workspaceId): void
    {
        Subscription::where('workspace_id', $workspaceId)->lockForUpdate()->first();
    }

    public function limits(string $workspaceId): array
    {
        $subscription = Subscription::where('workspace_id', $workspaceId)->first();
        $active = $subscription && in_array($subscription->status, ['active', 'trialing'], true)
            && (! $subscription->ends_at || $subscription->ends_at->isFuture());
        $plan = $active ? $subscription->plan : 'basic';

        return config('billing.plans.'.$plan.'.limits', config('billing.plans.basic.limits'));
    }

    public function assertCanCreate(string $workspaceId, string $resource, int $currentCount, int $adding = 1): void
    {
        $limits = $this->limits($workspaceId);
        if (! array_key_exists($resource, $limits) || $currentCount < 0 || $adding < 0) {
            throw new \InvalidArgumentException('Неизвестный ресурс или неверное количество.');
        }
        $limit = $limits[$resource];
        if ($limit !== null && $currentCount + $adding > $limit) {
            $title = ['asanas' => 'асан', 'practices' => 'практик и шаблонов', 'mesocycles' => 'мезоциклов'][$resource];
            throw ValidationException::withMessages(['subscription' => 'Лимит тарифа: '.$limit.' '.$title.'. Изменить доступ можно в личном кабинете через администратора.']);
        }
    }
}
