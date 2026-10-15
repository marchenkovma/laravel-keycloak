<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void storeSignIn(string $role, string|null $idToken)
 * @method static string|null role()
 * @method static string|null idToken()
 * @method static bool hasRole(string ...$roles)
 * @method static string|null roleFor(array<mixed> $claims)
 * @method static bool bypassEnabled()
 * @method static void resolveApiClientUsing(\Closure(array<string, mixed>): ?\Illuminate\Contracts\Auth\Authenticatable $resolver)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null apiClient(array<string, mixed> $claims)
 * @method static string serviceToken(string|null $clientId = null, string|null $clientSecret = null)
 * @method static string realmUrl()
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
