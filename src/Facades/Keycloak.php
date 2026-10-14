<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Marchenkovma\LaravelKeycloak\Keycloak
 */
class Keycloak extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Marchenkovma\LaravelKeycloak\Keycloak::class;
    }
}
