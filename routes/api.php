<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PromoController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Тікет 1
    Route::post('/promo/claim', [PromoController::class, 'claim'])
        ->middleware('throttle:10,1'); // захист від перебору кодів
    Route::get('/promo/history', [PromoController::class, 'history']);
});
