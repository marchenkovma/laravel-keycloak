# Черновик проверки токена API

Написан 2026-10-13 для employee-service, до решения выносить всё в пакет. Сверен с API `firebase/php-jwt` v7.1.0, но не запускался. Под пакет поменять:

- namespace;
- источник конфига (`config('keycloak')`, вычисление `issuer` и `jwks_url` из `base_url`/`realm`);
- разбор `clients` из строки.

```php
<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Проверка access-токенов сервисных клиентов Keycloak (client credentials).
 * На любой отказ возвращает null, а не исключение: при auth:keycloak,sanctum
 * guard keycloak должен уступить токен Sanctum.
 */
class KeycloakTokenVerifier
{
    private const JWKS_CACHE_KEY = 'keycloak.jwks';

    private const REFETCH_LOCK_KEY = 'keycloak.jwks.refetch';

    public function __construct(private array $config) {}

    public function verify(string $jwt): ?stdClass
    {
        // Токен Sanctum («1|abc…») не JWT: kid не найдётся, в Keycloak не ходим
        $kid = $this->kid($jwt);
        if ($kid === null) {
            return null;
        }

        $keys = $this->keys();

        // Неизвестный kid — ротация ключей в Keycloak. Перечитываем
        // не чаще раза в минуту, чтобы мусорный kid не дёргал Keycloak
        if ($keys !== null && ! isset($keys[$kid]) && Cache::add(self::REFETCH_LOCK_KEY, true, 60)) {
            $keys = $this->keys(refresh: true);
        }

        if ($keys === null || ! isset($keys[$kid])) {
            return null;
        }

        try {
            JWT::$leeway = $this->config['leeway'];
            $claims = JWT::decode($jwt, $keys);
        } catch (Throwable $e) {
            Log::info('Keycloak: токен отклонён', ['kid' => $kid, 'reason' => $e->getMessage()]);

            return null;
        }

        if (! $this->claimsValid($claims)) {
            Log::info('Keycloak: токен отклонён по claims', [
                'iss' => $claims->iss ?? null,
                'azp' => $claims->azp ?? null,
            ]);

            return null;
        }

        return $claims;
    }

    private function kid(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        } catch (Throwable) {
            return null;
        }

        return is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /**
     * В кеше лежит сырой JWKS: объекты Key с ключами OpenSSL не сериализуются.
     *
     * @return array<string, \Firebase\JWT\Key>|null
     */
    private function keys(bool $refresh = false): ?array
    {
        $jwks = $refresh ? null : Cache::get(self::JWKS_CACHE_KEY);
        $jwks ??= $this->fetch();

        if ($jwks === null) {
            return null;
        }

        // alg жёстко RS256: none отвергнет JWT::decode, а HS* не совпадёт
        // с алгоритмом ключа
        return JWK::parseKeySet([
            'keys' => array_map(fn (array $jwk) => ['alg' => 'RS256'] + $jwk, $jwks),
        ]);
    }

    private function fetch(): ?array
    {
        try {
            $jwks = Http::timeout(5)->get($this->config['jwks_url'])->throw()->json('keys', []);
        } catch (Throwable $e) {
            Log::warning('Keycloak: не удалось скачать JWKS', ['error' => $e->getMessage()]);

            return null;
        }

        // Только RSA-ключи подписи: Keycloak отдаёт рядом ключ шифрования RSA-OAEP
        $jwks = array_values(array_filter(
            $jwks,
            fn (array $jwk) => ($jwk['kty'] ?? null) === 'RSA' && ($jwk['use'] ?? 'sig') === 'sig',
        ));

        if ($jwks === []) {
            Log::warning('Keycloak: в JWKS нет RSA-ключей подписи');

            return null;
        }

        Cache::put(self::JWKS_CACHE_KEY, $jwks, $this->config['jwks_cache_ttl']);

        return $jwks;
    }

    private function claimsValid(stdClass $claims): bool
    {
        return isset($claims->exp)
            && ($claims->iss ?? null) === $this->config['issuer']
            && in_array($this->config['audience'], (array) ($claims->aud ?? []), true)
            && in_array($claims->azp ?? null, $this->config['clients'], true);
    }
}
```

## Заготовки для тестов

```php
function rsaKey(string $kid): array
{
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pkey, $private);
    $rsa = openssl_pkey_get_details($pkey)['rsa'];

    return [
        'kid' => $kid,
        'private' => $private,
        'jwk' => [
            'kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ],
    ];
}

// JWKS отдаётся из $this->jwks: тест ротации подменяет ключи на лету,
// null — Keycloak недоступен
Http::fake(fn () => $this->jwks === null
    ? Http::response('', 500)
    : Http::response(['keys' => $this->jwks]));

// Токен с alg none — руками, php-jwt такой не подпишет
$header = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'kid' => 'k1']));
$payload = JWT::urlsafeB64Encode(json_encode([...]));
$token = "{$header}.{$payload}.";
```
