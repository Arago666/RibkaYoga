<?php

use App\Modules\Sessions\SessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/practices/{id}/run', fn (string $id) => Inertia::render('sessions/Player', ['documentId' => $id]))->whereUuid('id');
    Route::get('/practice-history', fn () => Inertia::render('sessions/History'));
    Route::get('/api/executions/plan/{id}', [SessionController::class, 'plan'])->whereUuid('id');
    Route::get('/api/executions', [SessionController::class, 'index']);
    Route::get('/api/executions/export', [SessionController::class, 'export']);
    Route::post('/api/executions', [SessionController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/api/executions/{id}', [SessionController::class, 'show'])->whereUuid('id');
    Route::put('/api/executions/{id}', [SessionController::class, 'update'])->whereUuid('id')->middleware('throttle:120,1');
});
