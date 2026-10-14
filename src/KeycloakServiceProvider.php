<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use SocialiteProviders\Keycloak\Provider as KeycloakProvider;
use SocialiteProviders\Manager\Config as ProviderConfig;

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
        $this->registerSocialiteDriver();

        $this->loadRoutesFrom(__DIR__.'/../routes/keycloak.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/keycloak.php' => config_path('keycloak.php'),
        ], ['keycloak', 'keycloak-config']);
    }

    /**
     * Драйвер keycloak для Socialite. Настройки читаются из config('keycloak')
     * в момент создания драйвера, services.keycloak проекту не нужен.
     */
    private function registerSocialiteDriver(): void
    {
        $this->callAfterResolving(SocialiteFactory::class, function (SocialiteManager $socialite): void {
            // Laravel привязывает замыкание к менеджеру Socialite, поэтому
            // контейнер берётся из аргумента, а не из $this->app
            $socialite->extend('keycloak', function (Container $app) use ($socialite): KeycloakProvider {
                $config = $app->make(Config::class);

                $settings = [
                    'client_id' => $config->get('keycloak.client_id'),
                    'client_secret' => $config->get('keycloak.client_secret'),
                    'redirect' => '/'.trim((string) $config->get('keycloak.web.prefix'), '/').'/callback',
                ];

                /** @var KeycloakProvider $provider */
                $provider = $socialite->buildProvider(KeycloakProvider::class, $settings);

                // Ключи base_url и realms (с s) диктует socialiteproviders/keycloak
                return $provider->setConfig(new ProviderConfig(
                    $settings['client_id'],
                    $settings['client_secret'],
                    url($settings['redirect']),
                    [
                        'base_url' => $config->get('keycloak.base_url'),
                        'realms' => $config->get('keycloak.realm'),
                    ],
                ));
            });
        });
    }
}
