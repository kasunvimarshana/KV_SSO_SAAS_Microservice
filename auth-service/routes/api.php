<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/validate-token', [AuthController::class, 'validateToken']);
});

// Protected routes
Route::middleware(['jwt.auth', 'tenant'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // Tenant management (admin only)
    Route::prefix('tenants')->middleware('role:super_admin')->group(function () {
        Route::get('/', [TenantController::class, 'index']);
        Route::post('/', [TenantController::class, 'store']);
        Route::get('/{id}', [TenantController::class, 'show']);
        Route::put('/{id}', [TenantController::class, 'update']);
        Route::delete('/{id}', [TenantController::class, 'destroy']);
    });
});

Route::get('/health', fn() => response()->json(['status' => 'ok', 'service' => 'auth-service']));
