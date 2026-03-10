<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\SagaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'service' => 'order-service']));

Route::middleware(['jwt.auth', 'tenant'])->group(function () {
    // Order CRUD
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']); // Triggers Saga
        Route::get('/filter-by-product', [OrderController::class, 'filterByProduct']);
        Route::get('/{id}', [OrderController::class, 'show']);
        Route::put('/{id}', [OrderController::class, 'update']);
        Route::delete('/{id}', [OrderController::class, 'destroy']);
        Route::post('/{id}/cancel', [OrderController::class, 'cancel']); // Triggers compensation
        Route::post('/{id}/complete', [OrderController::class, 'complete']);
    });

    // Saga management and observability
    Route::prefix('sagas')->group(function () {
        Route::get('/', [SagaController::class, 'index']);
        Route::get('/{sagaId}', [SagaController::class, 'show']);
        Route::post('/{sagaId}/retry', [SagaController::class, 'retry']);
    });
});
