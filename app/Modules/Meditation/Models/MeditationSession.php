<?php

namespace App\Modules\Meditation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MeditationSession extends Model
{
    use HasUuids;

    protected $table = 'meditation_sessions';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['settings' => 'array', 'active_ms' => 'integer', 'revision' => 'integer', 'started_at' => 'immutable_datetime', 'active_since' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }
}
