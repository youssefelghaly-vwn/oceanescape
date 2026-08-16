<?php

namespace App\Providers;

use App\Services\Lodgify\LodgifyClient;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Support\ServiceProvider;

class LodgifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/lodgify.php', 'lodgify');

        $this->app->singleton(LodgifyClient::class);
        $this->app->singleton(LodgifyRepository::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/lodgify.php' => config_path('lodgify.php'),
        ], 'lodgify-config');
    }
}
