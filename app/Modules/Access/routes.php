<?php

use App\Modules\Access\Http\AccountController;
use App\Modules\Access\Http\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/account', [AccountController::class, 'page'])->name('account');
Route::get('/api/account', [AccountController::class, 'show']);
Route::post('/account/profile', [AccountController::class, 'update']);
Route::get('/account/avatar', [AccountController::class, 'avatar']);
Route::post('/api/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1');
Route::post('/api/account/subscription/cancel-renewal', [AccountController::class, 'cancelRenewal']);
Route::get('/admin', [AdminController::class, 'page'])->name('admin');
Route::get('/api/admin/users', [AdminController::class, 'index']);
Route::post('/api/admin/users/{userId}/grant', [AdminController::class, 'grant'])->whereNumber('userId');
Route::get('/api/admin/export', [AdminController::class, 'export']);
