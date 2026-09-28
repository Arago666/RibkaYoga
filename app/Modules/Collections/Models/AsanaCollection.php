<?php

namespace App\Modules\Collections\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsanaCollection extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'archived_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class, 'collection_id')->orderBy('position');
    }
}
