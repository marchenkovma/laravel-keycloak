<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Testing;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Marchenkovma\LaravelKeycloak\Api\TokenVerifier;
use RuntimeException;

/**
 * Помощники для тестов проектов (подключается в TestCase проекта).
 *
 * withKeycloakToken() шлёт запрос с настоящим RS256-токеном: ключ
 * генерируется в тесте, JWKS подменяется через Http::fake, так что guard
 * keycloak проверяет токен полностью, без Keycloak.
 */
trait InteractsWithKeycloak
{
    /** @var array{kid: string, private: string, jwk: array<string, string>}|null */
    private ?array $keycloakTestKey = null;

    /**
     * Следующие запросы теста идут с Authorization: Bearer <jwt>.
     * Claims по умолчанию из конфига: iss, aud, первый из api.clients.
     * null в значении убирает claim.
     *
     * @param  array<string, mixed>  $claims
     */
    public function withKeycloakToken(array $claims = []): static
    {
        // RequestGuard запоминает пользователя: без этого второй запрос
        // теста прошёл бы на клиенте из первого
        app('auth')->forgetGuards();

        return $this->withToken($this->keycloakToken($claims));
    }

    /**
     * Подписанный токен без отправки: для Http-клиента или своих заголовков.
     *
     * @param  array<string, mixed>  $claims
     */
    public function keycloakToken(array $claims = []): string
    {
        $key = $this->keycloakTestKey();
        $verifier = app(TokenVerifier::class);

        $payload = array_filter([
            'iss' => $verifier->issuer(),
            'aud' => config('keycloak.api.audience'),
            'azp' => $verifier->clients()[0] ?? null,
            'exp' => time() + 300,
            'iat' => time(),
            ...$claims,
        ], fn (mixed $value): bool => $value !== null);

        return JWT::encode($payload, $key['private'], 'RS256', $key['kid']);
    }

    /**
     * Вход пользователя с ролью приложения в сессии, без Socialite.
     */
    public function actingAsKeycloakUser(Authenticatable $user, string $role): static
    {
        return $this->actingAs($user)->withSession([
            'keycloak' => ['role' => $role, 'id_token' => 'fake-id-token'],
        ]);
    }

    /**
     * Пара RSA на тест и JWKS с её публичной частью. Http::fake вызывается
     * один раз: каждый вызов сбрасывает записанные запросы.
     *
     * @return array{kid: string, private: string, jwk: array<string, string>}
     */
    private function keycloakTestKey(): array
    {
        if ($this->keycloakTestKey !== null) {
            return $this->keycloakTestKey;
        }

        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = $pkey === false ? false : openssl_pkey_get_details($pkey);

        if ($pkey === false || $details === false || ! openssl_pkey_export($pkey, $private)) {
            throw new RuntimeException('OpenSSL could not generate an RSA key for the Keycloak test token.');
        }

        $this->keycloakTestKey = [
            'kid' => 'keycloak-test',
            'private' => $private,
            'jwk' => [
                'kid' => 'keycloak-test', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
            ],
        ];

        Http::fake([
            app(TokenVerifier::class)->jwksUrl() => Http::response(['keys' => [$this->keycloakTestKey['jwk']]]),
        ]);

        return $this->keycloakTestKey;
    }
}
