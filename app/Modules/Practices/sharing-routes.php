<?php

use App\Modules\Practices\Http\Controllers\DocumentSharingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/shared-practices', fn () => Inertia::render('practices/SharedIndex'));
    Route::get('/shared-practices/{id}', fn (string $id) => Inertia::render('practices/SharedPreview', ['documentId' => $id]))->whereUuid('id');
    Route::prefix('api')->group(function () {
        Route::get('/practices/{id}/shares', [DocumentSharingController::class, 'permissions'])->whereUuid('id');
        Route::post('/practices/{id}/shares', [DocumentSharingController::class, 'grant'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/practices/{id}/shares/{shareId}/revoke', [DocumentSharingController::class, 'revoke'])->whereUuid(['id', 'shareId']);
        Route::get('/shared-practices', [DocumentSharingController::class, 'index']);
        Route::get('/shared-practices/{id}', [DocumentSharingController::class, 'show'])->whereUuid('id');
        Route::get('/shared-practices/{id}/media/{assetId}', [DocumentSharingController::class, 'media'])->whereUuid(['id', 'assetId']);
        Route::post('/shared-practices/{id}/comments', [DocumentSharingController::class, 'addComment'])->whereUuid('id')->middleware('throttle:30,1');
        Route::patch('/shared-practices/{id}/comments/{commentId}', [DocumentSharingController::class, 'editComment'])->whereUuid(['id', 'commentId'])->middleware('throttle:30,1');
        Route::delete('/shared-practices/{id}/comments/{commentId}', [DocumentSharingController::class, 'deleteComment'])->whereUuid(['id', 'commentId']);
    });
});
