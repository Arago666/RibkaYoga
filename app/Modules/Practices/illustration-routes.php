<?php

use App\Modules\Practices\Http\Controllers\PracticeIllustrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'throttle:30,1'])->prefix('api/practices')->group(function () {
    Route::post('/{id}/illustration-preview', [PracticeIllustrationController::class, 'preview'])->whereUuid('id');
    Route::post('/{id}/illustration-apply', [PracticeIllustrationController::class, 'apply'])->whereUuid('id');
});
