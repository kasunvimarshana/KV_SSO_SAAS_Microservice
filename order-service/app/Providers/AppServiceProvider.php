<?php

namespace App\Providers;

use App\Services\InventoryServiceClient;
use App\Services\ProductServiceClient;
use App\Services\SagaOrchestrator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProductServiceClient::class, fn() => new ProductServiceClient());
        $this->app->singleton(InventoryServiceClient::class, fn() => new InventoryServiceClient());
        $this->app->singleton(SagaOrchestrator::class, function ($app) {
            return new SagaOrchestrator(
                $app->make(ProductServiceClient::class),
                $app->make(InventoryServiceClient::class),
            );
        });
    }

    public function boot(): void {}
}
