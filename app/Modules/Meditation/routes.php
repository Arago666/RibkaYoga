<?php

use App\Modules\Meditation\Http\MeditationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/meditation', fn () => Inertia::render('meditation/Index'))->name('meditation.index');
Route::prefix('api/meditation')->group(function () {
    Route::get('/settings', [MeditationController::class, 'settings']);
    Route::put('/settings', [MeditationController::class, 'saveSettings']);
    Route::get('/history', [MeditationController::class, 'history']);
    Route::get('/export', [MeditationController::class, 'export']);
    Route::get('/active', [MeditationController::class, 'active']);
    Route::post('/sessions', [MeditationController::class, 'start'])->middleware('throttle:60,1');
    Route::get('/sessions/{id}', [MeditationController::class, 'show'])->whereUuid('id');
    Route::post('/sessions/{id}', [MeditationController::class, 'command'])->whereUuid('id')->middleware('throttle:120,1');
});
