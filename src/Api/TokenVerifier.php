<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Marchenkovma\LaravelKeycloak\Keycloak;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Проверка access-токенов сервисных клиентов Keycloak (client credentials).
 *
 * На любой отказ возвращается null, а не исключение: при auth:keycloak,sanctum guard
 * keycloak должен уступить токен следующему guard-у. Сам токен не логируется.
 */
class TokenVerifier
{
    // Неизвестный kid перечитывает JWKS не чаще раза в эту паузу
    private const int REFETCH_INTERVAL = 60;

    public function __construct(
        private readonly Keycloak $keycloak,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Http $http,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @return array<string, mixed>|null проверенные claims или null
     */
    public function verify(string $jwt): ?array
    {
        // Не JWT (например, токен Sanctum «1|abc…»): мимо, без похода в Keycloak
        $kid = $this->kid($jwt);

        if ($kid === null) {
            return null;
        }

        $keys = $this->keys();

        // Неизвестный kid означает ротацию ключей в Keycloak. Замок через add():
        // мусорный kid не дёргает Keycloak чаще раза в минуту
        if ($keys !== null && ! isset($keys[$kid])
            && $this->cache->add($this->cacheKey('refetch'), true, self::REFETCH_INTERVAL)) {
            $keys = $this->keys(refresh: true);
        }

        if ($keys === null) {
            return null;
        }

        if (! isset($keys[$kid])) {
            $this->reject($jwt, $kid, 'unknown kid');

            return null;
        }

        try {
            // Глобальная статика php-jwt: ставить перед каждым decode
            JWT::$leeway = (int) $this->config->get('keycloak.api.leeway', 30);
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (Throwable $e) {
            $this->reject($jwt, $kid, $e->getMessage());

            return null;
        }

        $reason = $this->invalidClaim($claims);

        if ($reason !== null) {
            $this->reject($jwt, $kid, $reason);

            return null;
        }

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($claims), true);
    }

    public function issuer(): string
    {
        $issuer = $this->config->get('keycloak.api.issuer');

        return is_string($issuer) && $issuer !== '' ? $issuer : $this->keycloak->realmUrl();
    }

    public function jwksUrl(): string
    {
        return $this->keycloak->realmUrl().'/protocol/openid-connect/certs';
    }

    /**
     * @return list<string>
     */
    public function clients(): array
    {
        $clients = $this->config->get('keycloak.api.clients', '');
        $clients = is_array($clients) ? $clients : explode(',', is_string($clients) ? $clients : '');

        return array_values(array_filter(array_map(fn (mixed $client): string => is_scalar($client) ? trim((string) $client) : '', $clients)));
    }

    /**
     * Причина отказа по claims или null. Подпись, exp и nbf уже проверил php-jwt.
     *
     * @param  array<string, mixed>  $claims
     */
    private function invalidClaim(array $claims): ?string
    {
        $audience = $this->config->get('keycloak.api.audience');

        return match (true) {
            // php-jwt проверяет срок, только если exp есть
            ! isset($claims['exp']) => 'exp missing',
            ($claims['iss'] ?? null) !== $this->issuer() => 'iss mismatch',
            ! is_string($audience) || $audience === '' => 'audience not configured',
            ! in_array($audience, (array) ($claims['aud'] ?? []), true) => 'aud mismatch',
            ! in_array($claims['azp'] ?? null, $this->clients(), true) => 'azp not allowed',
            default => null,
        };
    }

    private function kid(string $jwt): ?string
    {
        $header = $this->segment($jwt, 0);

        return is_string($header['kid'] ?? null) && $header['kid'] !== '' ? $header['kid'] : null;
    }

    /**
     * Непроверенный JSON заголовка или payload: только для kid и логов.
     *
     * @return array<string, mixed>|null
     */
    private function segment(string $jwt, int $index): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($parts[$index]), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
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

        try {
            // alg жёстко RS256: none php-jwt не поддерживает, а HS* не совпадёт
            // с алгоритмом ключа. + оставляет наш alg, даже если в JWK другой
            return JWK::parseKeySet([
                'keys' => array_map(fn (array $jwk) => ['alg' => 'RS256'] + $jwk, $jwks),
            ]);
        } catch (Throwable $e) {
            $this->log->warning('Keycloak: JWKS не разобран', ['error' => $e->getMessage()]);

            return null;
        }
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

        $this->cache->put($this->cacheKey('jwks'), $jwks, (int) $this->config->get('keycloak.api.jwks_cache_ttl', 3600));

        /** @var list<array<string, mixed>> $jwks */
        return $jwks;
    }

    private function reject(string $jwt, string $kid, string $reason): void
    {
        // iss и azp из непроверенного payload: только чтобы понять, кто стучится
        $payload = $this->segment($jwt, 1) ?? [];

        $this->log->info('Keycloak: токен API отклонён', [
            'kid' => $kid,
            'iss' => is_string($payload['iss'] ?? null) ? $payload['iss'] : null,
            'azp' => is_string($payload['azp'] ?? null) ? $payload['azp'] : null,
            'reason' => $reason,
        ]);
    }

    // Ключ кеша от адреса JWKS: два realm-а в одном кеше не смешиваются
    private function cacheKey(string $name): string
    {
        return 'keycloak:'.$name.':'.hash('sha256', $this->jwksUrl());
    }
}
