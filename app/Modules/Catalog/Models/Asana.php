<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asana extends Model
{
    use HasUuids;

    protected $table = 'catalog_asanas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'revision' => 'integer', 'drawing_source' => 'array'];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AsanaAlias::class)->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'catalog_asana_category');
    }
}
