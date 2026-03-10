<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\JwtService::class, function () {
            return new \App\Services\JwtService();
        });
    }

    public function boot(): void
    {
        //
    }
}
