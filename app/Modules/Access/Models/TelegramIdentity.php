<?php

namespace App\Modules\Access\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramIdentity extends Model
{
    protected $table = 'access_telegram_identities';

    protected $guarded = [];
}
