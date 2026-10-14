<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null role()
 * @method static bool hasRole(string ...$roles)
 * @method static string|null roleFor(array<mixed> $groups, array<mixed> $clientRoles)
 * @method static bool bypassEnabled()
 *
 * @see \Marchenkovma\LaravelKeycloak\Keycloak
 */
class Keycloak extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Marchenkovma\LaravelKeycloak\Keycloak::class;
    }
}
