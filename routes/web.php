<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect('/practices'))->name('home');
Route::middleware('auth')->group(function () {
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
