<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Marchenkovma\LaravelKeycloak\Api\KeycloakClient;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

const JWKS_URL = 'https://keycloak.test/realms/corp/protocol/openid-connect/certs';

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
 * @param  array<string, mixed>  $claims  null в значении — убрать claim
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

beforeEach(function () {
    config([
        'keycloak.api.audience' => 'employee-service',
        'keycloak.api.clients' => 'portguard-api, other-api',
        'auth.guards.keycloak' => ['driver' => 'keycloak'],
        'auth.guards.other' => ['driver' => 'other'],
    ]);

    $this->key = rsaKey('k1');

    // JWKS отдаётся из $this->jwks: тест ротации подменяет ключи на лету,
    // null — Keycloak недоступен. Рядом ключ шифрования, как отдаёт Keycloak
    $this->jwks = [$this->key['jwk'], ['kid' => 'enc', 'kty' => 'RSA', 'alg' => 'RSA-OAEP', 'use' => 'enc', 'n' => 'xx', 'e' => 'AQAB']];

    Http::fake([JWKS_URL => fn () => $this->jwks === null
        ? Http::response('', 500)
        : Http::response(['keys' => $this->jwks])]);

    // Фиктивный второй guard: пускает только токен other-token
    Auth::viaRequest('other', fn (Request $request) => $request->bearerToken() === 'other-token'
        ? new GenericUser(['id' => 'other'])
        : null);

    Route::get('api/ping', fn (Request $request) => [
        'class' => $request->user()::class,
        'id' => $request->user()->getAuthIdentifier(),
    ])->middleware('auth:keycloak');

    Route::get('api/either', fn (Request $request) => ['id' => $request->user()->getAuthIdentifier()])
        ->middleware('auth:keycloak,other');
});

function ping(string $token): TestResponse
{
    // RequestGuard запоминает пользователя: без forgetGuards второй запрос
    // прошёл бы на пользователе из первого
    app('auth')->forgetGuards();

    return test()->getJson('api/ping', ['Authorization' => "Bearer {$token}"]);
}

function jwksRequests(): int
{
    return Http::recorded(fn (HttpRequest $request) => $request->url() === JWKS_URL)->count();
}

it('пускает токен разрешённого клиента, пользователь — KeycloakClient с azp', function () {
    ping(signedToken($this->key))
        ->assertOk()
        ->assertJson(['class' => KeycloakClient::class, 'id' => 'portguard-api']);
});

it('KeycloakClient отдаёт claims, роли resource_access и scope', function () {
    Route::get('api/client', function (Request $request) {
        /** @var KeycloakClient $client */
        $client = $request->user();

        return [
            'azp' => $client->claims['azp'],
            'role' => $client->hasRole('employee-service', 'read'),
            'foreign_role' => $client->hasRole('employee-service', 'write'),
            'scope' => $client->hasScope('email'),
            'foreign_scope' => $client->hasScope('admin'),
        ];
    })->middleware('auth:keycloak');

    $this->getJson('api/client', ['Authorization' => 'Bearer '.signedToken($this->key)])
        ->assertExactJson(['azp' => 'portguard-api', 'role' => true, 'foreign_role' => false, 'scope' => true, 'foreign_scope' => false]);
});

it('принимает aud строкой', function () {
    ping(signedToken($this->key, ['aud' => 'employee-service']))->assertOk();
});

it('берёт iss из api.issuer, если задан: публичный адрес отличается от base_url', function () {
    config(['keycloak.api.issuer' => 'https://sso.example.com/realms/corp']);

    ping(signedToken($this->key))->assertUnauthorized();
    ping(signedToken($this->key, ['iss' => 'https://sso.example.com/realms/corp']))->assertOk();
});

it('пускает в пределах leeway по exp', function () {
    ping(signedToken($this->key, ['exp' => time() - 10]))->assertOk();
});

it('отказывает (401)', function (string $jwt) {
    Log::spy();

    ping($jwt)->assertUnauthorized();

    // Отказ по подписи или claims — info с причиной, без самого токена
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Keycloak: токен API отклонён'
        && $context['kid'] === 'k1'
        && is_string($context['reason'])
        && ! str_contains((string) json_encode($context), $jwt));
})->with([
    'чужая подпись' => fn () => signedToken(['kid' => 'k1', 'private' => rsaKey('k1')['private']]),
    'просроченный' => fn () => signedToken($this->key, ['exp' => time() - 120]),
    'nbf в будущем' => fn () => signedToken($this->key, ['nbf' => time() + 120]),
    'чужой iss' => fn () => signedToken($this->key, ['iss' => 'https://evil.test/realms/corp']),
    'нет нужного aud' => fn () => signedToken($this->key, ['aud' => ['account']]),
    'azp не из списка' => fn () => signedToken($this->key, ['azp' => 'stranger']),
    'нет exp' => fn () => signedToken($this->key, ['exp' => null]),
    'alg none' => fn () => unsignedToken('none', 'k1'),
    'HS256, подписанный публичным ключом' => fn () => JWT::encode(
        ['iss' => 'https://keycloak.test/realms/corp', 'aud' => 'employee-service', 'azp' => 'portguard-api', 'exp' => time() + 300],
        (string) openssl_pkey_get_details(openssl_pkey_get_private($this->key['private']))['key'],
        'HS256',
        'k1',
    ),
]);

it('доверяет только ключам подписи: токен по ключу шифрования (use=enc) — 401', function () {
    $encryption = rsaKey('enc-real');
    $this->jwks[] = ['use' => 'enc', 'alg' => 'RSA-OAEP'] + $encryption['jwk'];

    ping(signedToken($encryption))->assertUnauthorized();
});

it('без настроенного audience отказывает всем', function () {
    config(['keycloak.api.audience' => null]);

    ping(signedToken($this->key))->assertUnauthorized();
});

it('JWKS недоступен — 401, а не 500, warning без токена', function () {
    $this->jwks = null;
    Log::spy();

    ping(signedToken($this->key))->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'JWKS'));
});

it('кеширует JWKS: второй запрос в Keycloak не ходит', function () {
    ping(signedToken($this->key))->assertOk();
    ping(signedToken($this->key))->assertOk();

    expect(jwksRequests())->toBe(1);
});

it('новый kid после ротации: JWKS перечитан, запрос прошёл', function () {
    ping(signedToken($this->key))->assertOk();

    $rotated = rsaKey('k2');
    $this->jwks = [$rotated['jwk']];

    ping(signedToken($rotated))->assertOk();

    expect(jwksRequests())->toBe(2);
});

it('мусорный kid перечитывает JWKS не чаще раза в минуту', function () {
    ping(signedToken($this->key))->assertOk();

    $garbage = rsaKey('garbage');
    ping(signedToken($garbage))->assertUnauthorized();
    ping(signedToken($garbage))->assertUnauthorized();

    expect(jwksRequests())->toBe(2);

    $this->travel(61)->seconds();
    ping(signedToken($garbage))->assertUnauthorized();

    expect(jwksRequests())->toBe(3);
});

it('не-JWT токен — null без HTTP-запросов', function (string $token) {
    ping($token)->assertUnauthorized();

    Http::assertNothingSent();
})->with([
    'Sanctum' => '1|abcdefghijklmnopqrstuvwxyz',
    'две части' => 'abc.def',
    'без kid' => JWT::urlsafeB64Encode('{"alg":"RS256"}').'.e30.sig',
]);

it('без заголовка Authorization — 401 без HTTP-запросов', function () {
    $this->getJson('api/ping')->assertUnauthorized();

    Http::assertNothingSent();
});

describe('уступает очередь следующему guard-у', function () {
    it('токен для other проходит на auth:keycloak,other', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer other-token'])
            ->assertOk()
            ->assertJson(['id' => 'other']);
    });

    it('токен Keycloak проходит на auth:keycloak,other', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer '.signedToken($this->key)])
            ->assertOk()
            ->assertJson(['id' => 'portguard-api']);
    });

    it('битый JWT не роняет запрос, а уступает: other его тоже не знает — 401', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer '.unsignedToken('none', 'k1')])
            ->assertUnauthorized();
    });
});

it('проект подменяет пользователя guard-а своим классом', function () {
    Keycloak::resolveApiClientUsing(fn (array $claims) => new GenericUser(['id' => 'api:'.$claims['azp']]));

    ping(signedToken($this->key))->assertOk()->assertJson(['class' => GenericUser::class, 'id' => 'api:portguard-api']);
});

it('свой резолвер может отказать, вернув null', function () {
    Keycloak::resolveApiClientUsing(fn (array $claims) => null);

    ping(signedToken($this->key))->assertUnauthorized();
});
