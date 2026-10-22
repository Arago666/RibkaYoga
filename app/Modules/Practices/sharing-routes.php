<?php

use App\Modules\Practices\Http\Controllers\DocumentOutputController;
use App\Modules\Practices\Http\Controllers\DocumentSharingController;
use App\Modules\Practices\Http\Controllers\PortablePracticeController;
use App\Modules\Practices\Http\Controllers\PublicPublicationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/shared-practices', fn () => Inertia::render('practices/SharedIndex'));
    Route::get('/shared-practices/{id}', fn (string $id) => Inertia::render('practices/SharedPreview', ['documentId' => $id]))->whereUuid('id');
    Route::prefix('api')->group(function () {
        Route::get('/practice-output-preferences', [DocumentOutputController::class, 'preferences']);
        Route::put('/practice-output-preferences', [DocumentOutputController::class, 'savePreferences']);
        Route::get('/practices/{id}/sheet', [DocumentOutputController::class, 'sheet'])->whereUuid('id');
        Route::get('/practices/{id}/pdf', [DocumentOutputController::class, 'pdf'])->whereUuid('id')->middleware('throttle:6,1');
        Route::get('/practices/{id}/bundle', [PortablePracticeController::class, 'export'])->whereUuid('id')->middleware('throttle:10,1');
        Route::post('/practice-bundles/preview', [PortablePracticeController::class, 'preview'])->middleware('throttle:uploads');
        Route::post('/practice-bundles/import', [PortablePracticeController::class, 'import'])->middleware('throttle:uploads');
        Route::get('/practices/{id}/publications', [PublicPublicationController::class, 'index'])->whereUuid('id');
        Route::post('/practices/{id}/publications', [PublicPublicationController::class, 'store'])->whereUuid('id')->middleware('throttle:20,1');
        Route::post('/practices/{id}/publications/preview', [PublicPublicationController::class, 'preview'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/practices/{id}/publications/{publicationId}/revoke', [PublicPublicationController::class, 'revoke'])->whereUuid(['id', 'publicationId']);
        Route::post('/shared-practices/{id}/copy', [PortablePracticeController::class, 'shared'])->whereUuid('id');
        Route::post('/published/{token}/copy', [PortablePracticeController::class, 'published'])->where('token', '[a-f0-9]{64}');
        Route::get('/practices/{id}/shares', [DocumentSharingController::class, 'permissions'])->whereUuid('id');
        Route::post('/practices/{id}/shares', [DocumentSharingController::class, 'grant'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/practices/{id}/shares/{shareId}/revoke', [DocumentSharingController::class, 'revoke'])->whereUuid(['id', 'shareId']);
        Route::get('/shared-practices', [DocumentSharingController::class, 'index']);
        Route::get('/shared-practices/{id}', [DocumentSharingController::class, 'show'])->whereUuid('id');
        Route::get('/shared-practices/{id}/sheet', [DocumentSharingController::class, 'sheet'])->whereUuid('id');
        Route::get('/shared-practices/{id}/media/{assetId}', [DocumentSharingController::class, 'media'])->whereUuid(['id', 'assetId']);
        Route::post('/shared-practices/{id}/comments', [DocumentSharingController::class, 'addComment'])->whereUuid('id')->middleware('throttle:30,1');
        Route::patch('/shared-practices/{id}/comments/{commentId}', [DocumentSharingController::class, 'editComment'])->whereUuid(['id', 'commentId'])->middleware('throttle:30,1');
        Route::delete('/shared-practices/{id}/comments/{commentId}', [DocumentSharingController::class, 'deleteComment'])->whereUuid(['id', 'commentId']);
    });
});

Route::middleware('web')->group(function () {
    Route::get('/p/{token}', [PublicPublicationController::class, 'page'])->where('token', '[a-f0-9]{64}');
    Route::get('/p/{token}/run', [PublicPublicationController::class, 'run'])->where('token', '[a-f0-9]{64}');
    Route::prefix('api/published/{token}')->where(['token' => '[a-f0-9]{64}'])->group(function () {
        Route::get('/', [PublicPublicationController::class, 'show']);
        Route::get('/plan', [PublicPublicationController::class, 'plan']);
        Route::get('/sheet', [PublicPublicationController::class, 'sheet']);
        Route::get('/qr', [PublicPublicationController::class, 'qr']);
        Route::get('/media/{assetId}', [PublicPublicationController::class, 'media'])->whereUuid('assetId')->middleware('throttle:1200,1');
    });
});
