<?php

use App\Modules\Practices\Http\Controllers\PracticeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('api')->group(function () {
    Route::post('editor/cards/resolve', [PracticeController::class, 'resolve'])->middleware('throttle:120,1');
    Route::get('practices', [PracticeController::class, 'index']);
    Route::post('practices', [PracticeController::class, 'store']);
    Route::post('practices/copy-local', [PracticeController::class, 'copyLocal']);
    Route::get('practices/{id}', [PracticeController::class, 'show'])->whereUuid('id');
    Route::put('practices/{id}', [PracticeController::class, 'update'])->whereUuid('id');
    Route::post('practices/{id}/duplicate', [PracticeController::class, 'duplicate'])->whereUuid('id');
    Route::post('practices/{id}/save-as-template', [PracticeController::class, 'saveAsTemplate'])->whereUuid('id');
    Route::post('practices/{id}/archive', [PracticeController::class, 'archive'])->whereUuid('id');
    Route::post('practices/{id}/restore', [PracticeController::class, 'restore'])->whereUuid('id');

    Route::get('row-templates', [PracticeController::class, 'rowIndex']);
    Route::post('row-templates', [PracticeController::class, 'rowStore']);
    Route::get('row-templates/{id}', [PracticeController::class, 'rowShow'])->whereUuid('id');
    Route::put('row-templates/{id}', [PracticeController::class, 'rowUpdate'])->whereUuid('id');
    Route::post('row-templates/{id}/archive', [PracticeController::class, 'rowArchive'])->whereUuid('id');
    Route::post('row-templates/{id}/restore', [PracticeController::class, 'rowRestore'])->whereUuid('id');
});
