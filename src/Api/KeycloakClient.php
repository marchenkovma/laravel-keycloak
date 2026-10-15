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
        return in_array($role, self::resourceRoles($this->claims, $resource) ?? [], true);
    }

    /**
     * Роли клиента $resource из resource_access.{resource}.roles, null без
     * записи этого клиента. Общий разбор для guard-а, входа пользователей
     * и hasRole(): одна роль строкой (mapper без Multivalued) становится
     * списком, а не строки отбрасываются.
     *
     * @internal
     *
     * @param  array<mixed>  $claims
     * @return list<string>|null
     */
    public static function resourceRoles(array $claims, string $resource): ?array
    {
        $access = $claims['resource_access'] ?? null;

        if (! is_array($access) || ! is_array($access[$resource] ?? null)) {
            return null;
        }

        return array_values(array_filter((array) ($access[$resource]['roles'] ?? []), 'is_string'));
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
