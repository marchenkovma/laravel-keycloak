<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Marchenkovma\LaravelKeycloak\Tests\TestCase;
use SocialiteProviders\Manager\OAuth2\User as OidcUser;
use Workbench\App\Models\User;

uses(TestCase::class)->in(__DIR__);

const KEYCLOAK_TEST_JWKS_URL = 'https://keycloak.test/realms/corp/protocol/openid-connect/certs';

/**
 * Socialite стоит на границе с сетью Keycloak и мокается целиком той же цепочкой,
 * что в тестах проектов.
 *
 * @param  array<mixed>  $groups
 * @param  array<mixed>  $roles
 */
function fakeKeycloakUser(array $groups = [], array $roles = [], ?string $nickname = 'ivanov'): void
{
    $oidcUser = (new OidcUser)->map([
        'id' => 'kc-uuid-1',
        'nickname' => $nickname,
        'name' => 'Иван Иванов',
        'email' => 'ivanov@example.com',
    ])->setRaw([
        'preferred_username' => $nickname,
        'groups' => $groups,
        'roles' => $roles,
    ]);

    $oidcUser->accessTokenResponseBody = ['id_token' => 'fake-id-token'];

    Socialite::shouldReceive('driver->enablePKCE->user')->andReturn($oidcUser);
}

// Keycloak вернул state, которого в сессии уже нет
function staleKeycloakState(): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('enablePKCE->user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('keycloak')->andReturn($driver);
}

function keycloakTestUser(string $username = 'test'): Authenticatable
{
    // Без firstOrNew: username не в $fillable модели workbench
    $user = User::query()->where('username', $username)->first() ?? new User;
    $user->forceFill(['username' => $username, 'name' => 'Тестовый Пользователь', 'email' => "{$username}@example.com"])->save();

    return $user;
}

/**
 * RSA-пара с JWK публичной части.
 *
 * @return array{kid: string, private: string, jwk: array<string, string>}
 */
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

/**
 * @param  array{kid: string, private: string}  $key
 * @param  array<string, mixed>  $claims  null в значении убирает claim
 */
function signedToken(array $key, array $claims = []): string
{
    $payload = array_filter([
        'iss' => 'https://keycloak.test/realms/corp',
        'aud' => ['employee-service', 'account'],
        'azp' => 'portguard-api',
        'exp' => time() + 300,
        'iat' => time(),
        'scope' => 'profile email',
        'resource_access' => ['employee-service' => ['roles' => ['read']]],
        ...$claims,
    ], fn ($value) => $value !== null);

    return JWT::encode($payload, $key['private'], 'RS256', $key['kid']);
}

// Заголовок и payload руками: php-jwt такие токены не подпишет
function unsignedToken(string $alg, string $kid): string
{
    $header = JWT::urlsafeB64Encode((string) json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $payload = JWT::urlsafeB64Encode((string) json_encode([
        'iss' => 'https://keycloak.test/realms/corp', 'aud' => 'employee-service',
        'azp' => 'portguard-api', 'exp' => time() + 300,
    ]));

    return "{$header}.{$payload}.";
}
