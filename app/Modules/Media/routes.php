<?php

use App\Modules\Media\Http\AssetController;
use Illuminate\Support\Facades\Route;

Route::get('/media/{id}', AssetController::class)->whereUuid('id')->name('media.show');
