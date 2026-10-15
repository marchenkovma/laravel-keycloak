<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Testing;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Auth\Authenticatable;
use Marchenkovma\LaravelKeycloak\Api\SigningKeys;
use Marchenkovma\LaravelKeycloak\Api\TokenVerifier;
use Marchenkovma\LaravelKeycloak\Keycloak;
use RuntimeException;

/**
 * Помощники для тестов проектов (подключается в TestCase проекта).
 *
 * withKeycloakToken() шлёт запрос с настоящим RS256-токеном: ключ
 * генерируется в тесте, его публичная часть подменяет ключи подписи
 * guard-а keycloak (FakeSigningKeys). Guard проверяет токен полностью,
 * в сеть и в кеш не ходит, и Http::fake проекта ему не мешает.
 */
trait InteractsWithKeycloak
{
    /** @var array{kid: string, private: string, public: string}|null */
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

        // При каждом токене заново: тест мог пересоздать приложение
        app()->instance(SigningKeys::class, new FakeSigningKeys([
            $key['kid'] => new Key($key['public'], 'RS256'),
        ]));

        $payload = array_filter([
            ...app(TokenVerifier::class)->acceptedClaims(),
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
        // Запись сессии пишет сам пакет, как при настоящем входе
        $this->actingAs($user)->startSession();
        app(Keycloak::class)->storeSignIn($role, 'fake-id-token');

        return $this;
    }

    /**
     * Пара RSA на тест: закрытая часть подписывает, публичная проверяет.
     *
     * @return array{kid: string, private: string, public: string}
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
            'public' => $details['key'],
        ];

        return $this->keycloakTestKey;
    }
}
