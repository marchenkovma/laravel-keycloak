<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak;

use Illuminate\Support\ServiceProvider;

class KeycloakServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/keycloak.php', 'keycloak');

        $this->app->singleton(Keycloak::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/keycloak.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/keycloak.php' => config_path('keycloak.php'),
        ], ['keycloak', 'keycloak-config']);
    }
}
