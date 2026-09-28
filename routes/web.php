<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect('/practices'))->name('home');
Route::middleware('auth')->group(function () {
    Route::get('/practices', fn () => Inertia::render('practices/Index'))->name('practices.index');
    Route::get('/library', fn () => Inertia::render('catalog/Library'))->name('library');
    Route::get('/practices/{id}/edit', fn (string $id) => Inertia::render('practices/Editor', ['documentId' => $id]));
    Route::get('/row-templates/{id}/edit', fn (string $id) => Inertia::render('practices/Editor', ['documentId' => $id, 'rowMode' => true]));
    Route::get('/practices/{id}/preview', fn (string $id) => Inertia::render('practices/Preview', ['documentId' => $id]));
    Route::get('/practices/{id}/print', fn (string $id) => Inertia::render('practices/Preview', ['documentId' => $id, 'printMode' => true]));
});
