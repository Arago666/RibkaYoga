<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    protected $table = 'billing_subscription_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
