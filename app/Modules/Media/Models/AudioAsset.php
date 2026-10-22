<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AudioAsset extends Model
{
    use HasUuids;

    protected $table = 'media_audio_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'bytes' => 'integer', 'duration_ms' => 'integer', 'revision' => 'integer'];
    }
}
