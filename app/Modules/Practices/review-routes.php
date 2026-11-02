<?php

use App\Modules\Practices\Http\Controllers\PracticeReviewController;
use App\Modules\Practices\Http\Controllers\ReviewOutputController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/practice-reviews', fn () => Inertia::render('reviews/Index'));
    Route::get('/practice-reviews/{id}/preview', fn (string $id) => Inertia::render('reviews/Preview', ['reviewId' => $id]))->whereUuid('id');
    Route::get('/practice-reviews/{id}', fn (string $id) => Inertia::render('reviews/Editor', ['reviewId' => $id]))->whereUuid('id');
    Route::prefix('api/practice-reviews')->group(function () {
        Route::get('/', [PracticeReviewController::class, 'index']);
        Route::get('/sources', [PracticeReviewController::class, 'sources']);
        Route::post('/', [PracticeReviewController::class, 'store'])->middleware('throttle:60,1');
        Route::get('/{id}', [PracticeReviewController::class, 'show'])->whereUuid('id');
        Route::put('/{id}', [PracticeReviewController::class, 'update'])->whereUuid('id')->middleware('throttle:120,1');
        Route::post('/{id}/copy', [PracticeReviewController::class, 'copy'])->whereUuid('id')->middleware('throttle:60,1');
        Route::post('/{id}/archive', [PracticeReviewController::class, 'archive'])->whereUuid('id');
        Route::post('/{id}/restore', [PracticeReviewController::class, 'restore'])->whereUuid('id');
        Route::get('/{id}/media/{assetId}', [PracticeReviewController::class, 'media'])->whereUuid(['id', 'assetId']);
        Route::get('/{id}/sheet', [ReviewOutputController::class, 'sheet'])->whereUuid('id')->middleware('throttle:30,1');
        Route::get('/{id}/pdf', [ReviewOutputController::class, 'pdf'])->whereUuid('id')->middleware('throttle:6,1');
    });
});
