<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/migrate', function () {
    Artisan::call('migrate', ['--force' => true]);

    return nl2br(Artisan::output());
});
Route::get('/migrate-fresh', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);

    return nl2br(Artisan::output());
});
Route::get('/migrate-fresh-seed', function () {
    Artisan::call('migrate:fresh', [
        '--seed' => true,
        '--force' => true,
    ]);

    return nl2br(Artisan::output());
});
