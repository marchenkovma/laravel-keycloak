<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null role()
 * @method static bool hasRole(string ...$roles)
 * @method static string|null roleFor(array<mixed> $groups, array<mixed> $clientRoles)
 * @method static bool bypassEnabled()
 * @method static void resolveApiClientUsing(\Closure(array<string, mixed>): ?\Illuminate\Contracts\Auth\Authenticatable $resolver)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null apiClient(array<string, mixed> $claims)
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
