<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Сервисный клиент Keycloak, вошедший в API по токену client credentials.
 * Ничего не хранит в базе: идентификатор это azp, остальное берётся из claims токена.
 */
class KeycloakClient implements Authenticatable
{
    /**
     * @param  array<string, mixed>  $claims  проверенные claims access-токена
     */
    public function __construct(public readonly array $claims) {}

    public function clientId(): string
    {
        return is_string($this->claims['azp'] ?? null) ? $this->claims['azp'] : '';
    }

    /**
     * Роль клиента $resource из resource_access (роли service account).
     */
    public function hasRole(string $resource, string $role): bool
    {
        $access = $this->claims['resource_access'] ?? [];
        $roles = is_array($access) && is_array($access[$resource] ?? null) ? ($access[$resource]['roles'] ?? []) : [];

        return is_array($roles) && in_array($role, $roles, true);
    }

    /**
     * Scope из claim scope (строка через пробел).
     */
    public function hasScope(string $scope): bool
    {
        $scopes = is_string($this->claims['scope'] ?? null) ? explode(' ', $this->claims['scope']) : [];

        return in_array($scope, $scopes, true);
    }

    public function getAuthIdentifierName(): string
    {
        return 'azp';
    }

    public function getAuthIdentifier(): string
    {
        return $this->clientId();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // У клиента API нет сессии и remember-токена
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
