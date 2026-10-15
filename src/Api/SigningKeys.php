<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Firebase\JWT\Key;

/**
 * Ключи подписи access-токенов. В приложении JwksSigningKeys (JWKS realm-а
 * через кеш), в тестах проектов FakeSigningKeys из трейта InteractsWithKeycloak.
 */
interface SigningKeys
{
    /**
     * Ключ по kid из заголовка токена или null: ключа нет или Keycloak недоступен.
     */
    public function find(string $kid): ?Key;
}
