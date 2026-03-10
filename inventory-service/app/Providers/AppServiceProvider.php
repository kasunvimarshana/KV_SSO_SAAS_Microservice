<?php

namespace App\Providers;

use App\Services\ProductServiceClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProductServiceClient::class, function () {
            return new ProductServiceClient();
        });
    }

    public function boot(): void {}
}
