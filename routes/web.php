<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['ok' => true, 'service' => 'madar-backend'], 200);
});

Route::get('/login', fn () => response()->json(['status' => 'healthy'], 200))->name('login');