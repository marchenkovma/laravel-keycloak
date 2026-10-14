<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests;

use Marchenkovma\LaravelKeycloak\KeycloakServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            KeycloakServiceProvider::class,
        ];
    }
}
