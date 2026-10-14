<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests;

use Laravel\Socialite\SocialiteServiceProvider;
use Marchenkovma\LaravelKeycloak\KeycloakServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // В проекте Socialite подключится автообнаружением, в Testbench — явно
        return [
            SocialiteServiceProvider::class,
            SocialiteProvidersServiceProvider::class,
            KeycloakServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('keycloak.base_url', 'https://keycloak.test');
        $app['config']->set('keycloak.realm', 'corp');
        $app['config']->set('keycloak.client_id', 'employees');
        $app['config']->set('keycloak.client_secret', 'web-secret');
    }
}
