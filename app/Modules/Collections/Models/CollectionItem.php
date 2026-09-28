<?php

namespace App\Modules\Collections\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionItem extends Model
{
    protected $table = 'asana_collection_items';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;
}
