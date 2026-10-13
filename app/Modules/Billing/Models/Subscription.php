<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;

    protected $table = 'billing_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancel_at_period_end' => 'boolean', 'revision' => 'integer'];
    }
}
