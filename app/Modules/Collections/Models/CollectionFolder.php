<?php

namespace App\Modules\Collections\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CollectionFolder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
