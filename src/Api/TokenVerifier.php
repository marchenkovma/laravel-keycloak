<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Config\Repository as Config;
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
    public function __construct(
        private readonly Keycloak $keycloak,
        private readonly Config $config,
        private readonly SigningKeys $keys,
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

        $key = $this->keys->find($kid);

        if ($key === null) {
            $this->reject($jwt, $kid, 'no signing key for kid');

            return null;
        }

        try {
            // Глобальная статика php-jwt: ставить перед каждым decode
            JWT::$leeway = (int) $this->config->get('keycloak.api.leeway', 30);
            $claims = (array) JWT::decode($jwt, $key);
        } catch (Throwable $e) {
            $this->reject($jwt, $kid, $e->getMessage());

            return null;
        }

        // php-jwt отдаёт вложенные claims объектами, resource_access нужен массивом
        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($claims), true);

        $reason = $this->invalidClaim($claims);

        if ($reason !== null) {
            $this->reject($jwt, $kid, $reason);

            return null;
        }

        return $claims;
    }

    /**
     * Claims, с которыми токен пройдёт проверку: iss, aud, первый из
     * разрешённых azp и роль api.role в resource_access. По ним трейт
     * InteractsWithKeycloak собирает тестовый токен.
     *
     * @return array{iss: string, aud: string|null, azp: string|null, resource_access: array<string, array{roles: list<string>}>|null}
     */
    public function acceptedClaims(): array
    {
        $audience = $this->audience();
        $role = $this->role();

        return [
            'iss' => $this->issuer(),
            'aud' => $audience,
            'azp' => $this->clients()[0] ?? null,
            'resource_access' => $audience !== null && $role !== null ? [$audience => ['roles' => [$role]]] : null,
        ];
    }

    /**
     * Причина отказа по claims или null. Подпись, exp и nbf уже проверил php-jwt.
     *
     * @param  array<string, mixed>  $claims
     */
    private function invalidClaim(array $claims): ?string
    {
        $audience = $this->audience();

        if ($audience === null) {
            return 'audience not configured';
        }

        $role = $this->role();
        $clients = $this->clients();

        return match (true) {
            // php-jwt проверяет срок, только если exp есть
            ! isset($claims['exp']) => 'exp missing',
            ($claims['iss'] ?? null) !== $this->issuer() => 'iss mismatch',
            ! in_array($audience, (array) ($claims['aud'] ?? []), true) => 'aud mismatch',
            // Одного aud для доступа мало: audience mapper легко поставить
            // в общий client scope, и доступ получили бы все клиенты
            $role === null && $clients === [] => 'access rule not configured',
            // Роли под client id принимающего API: его ставит в aud Audience Resolve
            $role !== null && ! in_array($role, KeycloakClient::resourceRoles($claims, $audience) ?? [], true) => 'role missing',
            $clients !== [] && ! in_array($claims['azp'] ?? null, $clients, true) => 'azp not allowed',
            default => null,
        };
    }

    private function issuer(): string
    {
        $issuer = $this->config->get('keycloak.api.issuer');

        return is_string($issuer) && $issuer !== '' ? $issuer : $this->keycloak->realmUrl();
    }

    private function audience(): ?string
    {
        $audience = $this->config->get('keycloak.api.audience');

        return is_string($audience) && $audience !== '' ? $audience : null;
    }

    private function role(): ?string
    {
        $role = $this->config->get('keycloak.api.role');

        return is_string($role) && trim($role) !== '' ? trim($role) : null;
    }

    /**
     * @return list<string>
     */
    private function clients(): array
    {
        $clients = $this->config->get('keycloak.api.clients', '');
        $clients = is_array($clients) ? $clients : explode(',', is_string($clients) ? $clients : '');

        return array_values(array_filter(array_map(fn (mixed $client): string => is_scalar($client) ? trim((string) $client) : '', $clients)));
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
}
