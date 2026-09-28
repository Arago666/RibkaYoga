<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasUuids;

    protected $table = 'media_assets';

    protected $guarded = [];

    public const UPDATED_AT = null;
}
