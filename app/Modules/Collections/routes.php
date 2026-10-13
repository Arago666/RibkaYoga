<?php

use App\Modules\Collections\Http\CollectionController;
use App\Modules\Collections\Http\CollectionFolderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/collection-folders')->group(function () {
    Route::get('/', [CollectionFolderController::class, 'index']);
    Route::post('/', [CollectionFolderController::class, 'store']);
    Route::patch('/{id}', [CollectionFolderController::class, 'update'])->whereUuid('id');
    Route::delete('/{id}', [CollectionFolderController::class, 'destroy'])->whereUuid('id');
});

Route::prefix('api/collections')->group(function () {
    Route::get('/', [CollectionController::class, 'index']);
    Route::post('/', [CollectionController::class, 'store']);
    Route::get('/{id}', [CollectionController::class, 'show'])->whereUuid('id');
    Route::patch('/{id}', [CollectionController::class, 'update'])->whereUuid('id');
    Route::post('/{id}/archive', [CollectionController::class, 'archive'])->whereUuid('id');
    Route::post('/{id}/restore', [CollectionController::class, 'restore'])->whereUuid('id');
});
