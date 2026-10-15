<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Marchenkovma\LaravelKeycloak\Keycloak;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ключи подписи из JWKS realm-а. Сырой JWKS лежит в кеше приложения,
 * неизвестный kid перечитывает его (ротация ключей в Keycloak).
 */
class JwksSigningKeys implements SigningKeys
{
    // Неизвестный kid перечитывает JWKS не чаще раза в эту паузу
    private const int REFETCH_INTERVAL = 60;

    // Сырой JWKS, из которого получены разобранные ключи
    /** @var array<mixed>|null */
    private ?array $parsedFrom = null;

    /** @var array<string, Key> */
    private array $parsed = [];

    public function __construct(
        private readonly Keycloak $keycloak,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Http $http,
        private readonly LoggerInterface $log,
    ) {}

    public function find(string $kid): ?Key
    {
        $keys = $this->keys();

        // Неизвестный kid означает ротацию ключей в Keycloak. Замок через add():
        // мусорный kid не дёргает Keycloak чаще раза в минуту
        if ($keys !== null && ! isset($keys[$kid])
            && $this->cache->add($this->cacheKey('refetch'), true, self::REFETCH_INTERVAL)) {
            $keys = $this->keys(refresh: true);

            // Keycloak не ответил: замок снимается, иначе при ротации ключей
            // новые токены отклонялись бы минуту без повторной попытки
            if ($keys === null) {
                $this->cache->forget($this->cacheKey('refetch'));
            }
        }

        return $keys[$kid] ?? null;
    }

    /**
     * В кеше лежит сырой JWKS: объекты Key с ключами OpenSSL не сериализуются.
     *
     * @return array<string, Key>|null
     */
    private function keys(bool $refresh = false): ?array
    {
        $jwks = $refresh ? null : $this->cache->get($this->cacheKey('jwks'));
        $jwks = is_array($jwks) ? $jwks : $this->fetch();

        if ($jwks === null) {
            return null;
        }

        // Разбор JWK стоит вызова OpenSSL на каждый ключ. Объект живёт как
        // singleton, поэтому под Octane и в очередях разобранные ключи
        // переиспользуются, пока в кеше тот же JWKS. TTL и ротацию по-прежнему
        // решает кеш
        if ($jwks === $this->parsedFrom) {
            return $this->parsed;
        }

        try {
            // alg жёстко RS256: none php-jwt не поддерживает, а HS* не совпадёт
            // с алгоритмом ключа. + оставляет наш alg, даже если в JWK другой
            $parsed = JWK::parseKeySet([
                'keys' => array_map(fn (array $jwk) => ['alg' => 'RS256'] + $jwk, $jwks),
            ]);
        } catch (Throwable $e) {
            $this->log->warning('Keycloak: JWKS не разобран', ['error' => $e->getMessage()]);

            return null;
        }

        $this->parsedFrom = $jwks;

        return $this->parsed = $parsed;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetch(): ?array
    {
        try {
            $keys = $this->http->timeout(5)->get($this->jwksUrl())->throw()->json('keys', []);
        } catch (Throwable $e) {
            $this->log->warning('Keycloak: не удалось скачать JWKS', ['url' => $this->jwksUrl(), 'error' => $e->getMessage()]);

            return null;
        }

        // Только RSA-ключи подписи: Keycloak отдаёт рядом ключ шифрования RSA-OAEP
        $jwks = array_values(array_filter(
            is_array($keys) ? $keys : [],
            fn (mixed $jwk): bool => is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' && ($jwk['use'] ?? 'sig') === 'sig',
        ));

        if ($jwks === []) {
            $this->log->warning('Keycloak: в JWKS нет RSA-ключей подписи', ['url' => $this->jwksUrl()]);

            return null;
        }

        /** @var list<array<string, mixed>> $jwks */
        $this->cache->put($this->cacheKey('jwks'), $jwks, (int) $this->config->get('keycloak.api.jwks_cache_ttl', 3600));

        return $jwks;
    }

    private function jwksUrl(): string
    {
        return $this->keycloak->realmUrl().'/protocol/openid-connect/certs';
    }

    // Ключ кеша от адреса JWKS: два realm-а в одном кеше не смешиваются
    private function cacheKey(string $name): string
    {
        return 'keycloak:'.$name.':'.hash('sha256', $this->jwksUrl());
    }
}
