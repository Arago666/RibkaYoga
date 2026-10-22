<?php

use App\Modules\Sketchbook\DrawingTransferController;
use App\Modules\Sketchbook\SketchbookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/sketchbook', fn () => Inertia::render('sketchbook/Index'));
    Route::post('/api/drawing-snapshots', [DrawingTransferController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/api/native-images/{id}', [DrawingTransferController::class, 'source'])->whereUuid('id');
    Route::get('/sketchbook/{id}', fn (string $id) => Inertia::render('sketchbook/Page', ['pageId' => $id]))->whereUuid('id');
    Route::get('/api/sketchbook', [SketchbookController::class, 'index']);
    Route::post('/api/sketchbook', [SketchbookController::class, 'store']);
    Route::get('/api/sketchbook/{id}', [SketchbookController::class, 'show'])->whereUuid('id');
    Route::put('/api/sketchbook/{id}', [SketchbookController::class, 'update'])->whereUuid('id');
    Route::post('/api/sketchbook/{id}/copy', [SketchbookController::class, 'copy'])->whereUuid('id');
    Route::post('/api/sketchbook/{id}/archive', [SketchbookController::class, 'archive'])->whereUuid('id');
});
