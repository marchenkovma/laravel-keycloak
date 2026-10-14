<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Marchenkovma\LaravelKeycloak\Api\KeycloakClient;

class Keycloak
{
    /** @var (Closure(array<string, mixed>): ?Authenticatable)|null */
    private ?Closure $apiClientResolver = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    /**
     * Роль приложения из сессии: её кладёт callback входа.
     */
    public function role(): ?string
    {
        $role = $this->app->make('session')->get('keycloak.role');

        return is_string($role) ? $role : null;
    }

    /**
     * Есть ли у вошедшего пользователя одна из ролей.
     */
    public function hasRole(string ...$roles): bool
    {
        $role = $this->role();

        return $role !== null && in_array($role, $roles, true);
    }

    /**
     * Роль по группам (claim `groups`) и ролям клиента (claim `roles`):
     * первая совпавшая из keycloak.web.roles, иначе null (нет доступа).
     *
     * @param  array<mixed>  $groups
     * @param  array<mixed>  $clientRoles
     */
    public function roleFor(array $groups, array $clientRoles): ?string
    {
        /** @var array<string, array{groups?: list<string>, client_roles?: list<string>}> $roles */
        $roles = $this->config->get('keycloak.web.roles', []);

        foreach ($roles as $role => $rule) {
            if (array_intersect($rule['groups'] ?? [], $groups) !== []
                || array_intersect($rule['client_roles'] ?? [], $clientRoles) !== []) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Вход без Keycloak для локальной разработки. В production выключен
     * намертво, что бы ни лежало в env.
     */
    public function bypassEnabled(): bool
    {
        return $this->config->get('keycloak.web.bypass') === true && ! $this->app->isProduction();
    }

    /**
     * Свой пользователь guard-а keycloak вместо KeycloakClient, например
     * несохранённая модель. Замыкание получает проверенные claims токена;
     * null означает отказ (401 или следующий guard).
     *
     * @param  Closure(array<string, mixed>): ?Authenticatable  $resolver
     */
    public function resolveApiClientUsing(Closure $resolver): void
    {
        $this->apiClientResolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function apiClient(array $claims): ?Authenticatable
    {
        return $this->apiClientResolver !== null
            ? ($this->apiClientResolver)($claims)
            : new KeycloakClient($claims);
    }
}
