<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class AsanaAlias extends Model
{
    protected $table = 'catalog_asana_aliases';

    protected $guarded = [];

    public $timestamps = false;
}
