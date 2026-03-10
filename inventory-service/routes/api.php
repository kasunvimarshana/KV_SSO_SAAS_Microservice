<?php

use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'service' => 'inventory-service']));

Route::middleware(['jwt.auth', 'tenant'])->group(function () {
    // Inventory CRUD
    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::post('/', [InventoryController::class, 'store']);
        Route::get('/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/by-product/{productId}', [InventoryController::class, 'byProduct']);
        Route::get('/filter-by-product-attributes', [InventoryController::class, 'filterByProductAttributes']);
        Route::get('/{id}', [InventoryController::class, 'show']);
        Route::put('/{id}', [InventoryController::class, 'update']);
        Route::delete('/{id}', [InventoryController::class, 'destroy']);

        // Stock operations
        Route::post('/{id}/reserve', [InventoryController::class, 'reserve']);
        Route::post('/{id}/release', [InventoryController::class, 'release']);
        Route::post('/{id}/adjust', [InventoryController::class, 'adjust']);
    });

    // Inventory movements / audit trail
    Route::prefix('inventory-movements')->group(function () {
        Route::get('/', [InventoryMovementController::class, 'index']);
        Route::get('/{id}', [InventoryMovementController::class, 'show']);
    });
});
