<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]);

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());
