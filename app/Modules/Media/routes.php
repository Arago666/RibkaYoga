<?php

use App\Modules\Media\Http\AssetController;
use App\Modules\Media\Http\AudioController;
use Illuminate\Support\Facades\Route;

Route::get('/media/{id}', AssetController::class)->whereUuid('id')->name('media.show');
Route::get('/media/audio/{id}', [AudioController::class, 'stream'])->whereUuid('id')->name('media.audio');
Route::get('/api/audio', [AudioController::class, 'index']);
Route::post('/api/audio', [AudioController::class, 'store'])->middleware('throttle:10,1');
Route::patch('/api/audio/{id}', [AudioController::class, 'update'])->whereUuid('id');
