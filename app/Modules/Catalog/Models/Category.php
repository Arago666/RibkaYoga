<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasUuids;

    protected $table = 'catalog_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'sort_order' => 'integer'];
    }
}
