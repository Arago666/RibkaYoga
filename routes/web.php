<?php

use App\Modules\Access\Http\TelegramBotController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/mini-app', function (Request $request) {
    // UI context only: a Telegram launch never grants a site account.
    $request->session()->put('mini_app', true);
    if (! $request->user()) {
        $request->session()->put('url.intended', route('dashboard'));
    }

    return redirect()->route($request->user() ? 'dashboard' : 'login');
})->name('mini-app');
Route::post('/telegram/webhook', TelegramBotController::class)
    ->middleware('throttle:120,1')
    ->name('telegram.webhook');

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('dashboard');
    }
    $request->session()->forget('mini_app');

    return Inertia::render('presentation/Home', ['socialImage' => url('/images/brand/glass-garden-hero.webp')]);
})->name('home');
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('access/Home'))->name('dashboard');
    // Temporarily hidden; saved source data and images remain intact.
    Route::redirect('/pose-editor', '/library')->name('pose-editor');
    Route::get('/practices', fn () => Inertia::render('practices/Index'))->name('practices.index');
    Route::get('/mesocycles', fn () => Inertia::render('practices/Mesocycles'))->name('mesocycles.index');
    Route::get('/mesocycles/{id}/edit', fn (string $id) => Inertia::render('practices/Mesocycle', ['mesocycleId' => $id]))->whereUuid('id');
    Route::get('/settings', fn () => Inertia::render('access/Settings'))->name('settings');
    Route::get('/library', fn () => Inertia::render('catalog/Library'))->name('library');
    Route::get('/templates', fn () => Inertia::render('practices/Templates'))->name('templates');
    Route::get('/practice-folders', fn () => Inertia::render('practices/Folders'))->name('practice-folders');
    Route::get('/practices/{id}/edit', fn (string $id) => Inertia::render('practices/Editor', ['documentId' => $id]));
    Route::get('/row-templates/{id}/edit', fn (string $id) => Inertia::render('practices/Editor', ['documentId' => $id, 'rowMode' => true]));
    Route::get('/practices/{id}/preview', fn (string $id) => Inertia::render('practices/Preview', ['documentId' => $id]));
    Route::get('/practices/{id}/print', fn (string $id) => Inertia::render('practices/Preview', ['documentId' => $id, 'printMode' => true]));
});
