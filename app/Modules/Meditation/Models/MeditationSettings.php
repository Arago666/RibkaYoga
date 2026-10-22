<?php

namespace App\Modules\Meditation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MeditationSettings extends Model
{
    use HasUuids;

    protected $table = 'meditation_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'revision' => 'integer'];
    }
}
