<?php

use App\Modules\Catalog\Http\AsanaController;
use App\Modules\Catalog\Http\CategoryController;
use App\Modules\Catalog\Http\ExchangeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('catalog-json/export', [ExchangeController::class, 'export']);
    Route::post('catalog-json/import', [ExchangeController::class, 'import'])->middleware('throttle:uploads');
    Route::get('asanas/facets', [AsanaController::class, 'facetCounts']);
    Route::post('asanas/classify', [AsanaController::class, 'classify']);
    Route::get('asanas', [AsanaController::class, 'index']);
    Route::post('asanas', [AsanaController::class, 'store'])->middleware('throttle:uploads');
    Route::get('asanas/{id}', [AsanaController::class, 'show'])->whereUuid('id');
    Route::patch('asanas/{id}', [AsanaController::class, 'update'])->whereUuid('id')->middleware('throttle:uploads');
    Route::post('asanas/{id}/duplicate', [AsanaController::class, 'duplicate'])->whereUuid('id')->middleware('throttle:uploads');
    Route::put('asanas/{id}/favorite', [AsanaController::class, 'favorite'])->whereUuid('id');
    Route::post('asanas/{id}/archive', [AsanaController::class, 'archive'])->whereUuid('id');
    Route::post('asanas/{id}/restore', [AsanaController::class, 'restore'])->whereUuid('id');
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::patch('categories/{id}', [CategoryController::class, 'update'])->whereUuid('id');
    Route::delete('categories/{id}', [CategoryController::class, 'destroy'])->whereUuid('id');
});
