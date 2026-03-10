<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'service' => 'user-service']));

// All user routes require authentication
Route::middleware(['jwt.auth', 'tenant'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::get('/{id}/profile', [UserController::class, 'profile']);
    Route::put('/{id}/status', [UserController::class, 'updateStatus']);
});
