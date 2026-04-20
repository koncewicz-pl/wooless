<?php

namespace App\Providers;

use App\Services\WooCommerce\ClientFactory;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WooCommerceClient::class, function ($app) {
            return $app->make(ClientFactory::class)->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
