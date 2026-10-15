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

beforeEach(function () {
    config([
        'keycloak.api.audience' => 'orders-api',
        'keycloak.api.clients' => 'reports-api, other-api',
        'auth.guards.keycloak' => ['driver' => 'keycloak'],
        'auth.guards.other' => ['driver' => 'other'],
    ]);

    $this->key = rsaKey('k1');

    // JWKS отдаётся из $this->jwks: тест ротации подменяет ключи на лету,
    // null значит, что Keycloak недоступен. Рядом ключ шифрования, как отдаёт Keycloak
    $this->jwks = [$this->key['jwk'], ['kid' => 'enc', 'kty' => 'RSA', 'alg' => 'RSA-OAEP', 'use' => 'enc', 'n' => 'xx', 'e' => 'AQAB']];

    Http::fake([KEYCLOAK_TEST_JWKS_URL => fn () => $this->jwks === null
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
    return Http::recorded(fn (HttpRequest $request) => $request->url() === KEYCLOAK_TEST_JWKS_URL)->count();
}

it('allows a token of a permitted client, the user is KeycloakClient with azp', function () {
    ping(signedToken($this->key))
        ->assertOk()
        ->assertJson(['class' => KeycloakClient::class, 'id' => 'reports-api']);
});

it('KeycloakClient exposes claims, resource_access roles and scope', function () {
    Route::get('api/client', function (Request $request) {
        /** @var KeycloakClient $client */
        $client = $request->user();

        return [
            'azp' => $client->claims['azp'],
            'role' => $client->hasRole('orders-api', 'read'),
            'foreign_role' => $client->hasRole('orders-api', 'write'),
            'scope' => $client->hasScope('email'),
            'foreign_scope' => $client->hasScope('admin'),
        ];
    })->middleware('auth:keycloak');

    $this->getJson('api/client', ['Authorization' => 'Bearer '.signedToken($this->key)])
        ->assertExactJson(['azp' => 'reports-api', 'role' => true, 'foreign_role' => false, 'scope' => true, 'foreign_scope' => false]);
});

it('KeycloakClient::hasRole accepts roles as a string: a mapper without Multivalued', function () {
    $client = new KeycloakClient(['resource_access' => ['orders-api' => ['roles' => 'read']]]);

    expect($client->hasRole('orders-api', 'read'))->toBeTrue()
        ->and($client->hasRole('orders-api', 'write'))->toBeFalse()
        ->and($client->hasRole('other-api', 'read'))->toBeFalse();
});

it('KeycloakClient::hasRole survives a malformed resource_access', function (mixed $resourceAccess) {
    expect((new KeycloakClient(['resource_access' => $resourceAccess]))->hasRole('orders-api', 'read'))->toBeFalse();
})->with([
    'string' => ['orders-api'],
    'client entry is a string' => [['orders-api' => 'read']],
    'roles with non-strings' => [['orders-api' => ['roles' => [null, ['read'], 42]]]],
]);

it('accepts aud as a string', function () {
    ping(signedToken($this->key, ['aud' => 'orders-api']))->assertOk();
});

it('takes iss from api.issuer when set: the public address differs from base_url', function () {
    config(['keycloak.api.issuer' => 'https://sso.example.com/realms/corp']);

    ping(signedToken($this->key))->assertUnauthorized();
    ping(signedToken($this->key, ['iss' => 'https://sso.example.com/realms/corp']))->assertOk();
});

it('allows exp within the leeway', function () {
    ping(signedToken($this->key, ['exp' => time() - 10]))->assertOk();
});

it('refuses (401)', function (string $jwt) {
    Log::spy();

    ping($jwt)->assertUnauthorized();

    // Отказ по подписи или claims пишется в info с причиной, без самого токена
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Keycloak: токен API отклонён'
        && $context['kid'] === 'k1'
        && is_string($context['reason'])
        && ! str_contains((string) json_encode($context), $jwt));
})->with([
    'foreign signature' => fn () => signedToken(['kid' => 'k1', 'private' => rsaKey('k1')['private']]),
    'expired' => fn () => signedToken($this->key, ['exp' => time() - 120]),
    'nbf in the future' => fn () => signedToken($this->key, ['nbf' => time() + 120]),
    'foreign iss' => fn () => signedToken($this->key, ['iss' => 'https://evil.test/realms/corp']),
    'required aud missing' => fn () => signedToken($this->key, ['aud' => ['account']]),
    'azp not in the list' => fn () => signedToken($this->key, ['azp' => 'stranger']),
    'no exp' => fn () => signedToken($this->key, ['exp' => null]),
    'alg none' => fn () => unsignedToken('none', 'k1'),
    'HS256 signed with the public key' => fn () => JWT::encode(
        ['iss' => 'https://keycloak.test/realms/corp', 'aud' => 'orders-api', 'azp' => 'reports-api', 'exp' => time() + 300],
        (string) openssl_pkey_get_details(openssl_pkey_get_private($this->key['private']))['key'],
        'HS256',
        'k1',
    ),
]);

it('trusts signing keys only: a token signed with the encryption key (use=enc) gets 401', function () {
    $encryption = rsaKey('enc-real');
    $this->jwks[] = ['use' => 'enc', 'alg' => 'RSA-OAEP'] + $encryption['jwk'];

    ping(signedToken($encryption))->assertUnauthorized();
});

it('refuses everyone without a configured audience', function () {
    config(['keycloak.api.audience' => null]);

    ping(signedToken($this->key))->assertUnauthorized();
});

it('refuses everyone without api.role and api.clients: aud alone grants no access', function () {
    config(['keycloak.api.role' => null, 'keycloak.api.clients' => '']);
    Log::spy();

    ping(signedToken($this->key))->assertUnauthorized();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['reason'] === 'access rule not configured');
});

describe('access by client role', function () {
    beforeEach(function () {
        config(['keycloak.api.role' => 'api', 'keycloak.api.clients' => '']);
    });

    it('allows a token with the role in resource_access of the audience, whatever the azp', function () {
        ping(signedToken($this->key, ['azp' => 'stranger', 'resource_access' => ['orders-api' => ['roles' => ['read', 'api']]]]))
            ->assertOk()
            ->assertJson(['id' => 'stranger']);
    });

    it('accepts roles as a string: a mapper without Multivalued', function () {
        ping(signedToken($this->key, ['resource_access' => ['orders-api' => ['roles' => 'api']]]))->assertOk();
    });

    it('refuses without the role (401, role missing)', function (mixed $resourceAccess) {
        Log::spy();

        ping(signedToken($this->key, ['resource_access' => $resourceAccess]))->assertUnauthorized();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['reason'] === 'role missing');
    })->with([
        'no resource_access' => [null],
        'another role of the same client' => [['orders-api' => ['roles' => ['read']]]],
        // Роль одного клиента не открывает API другого
        'the role under another client' => [['other-api' => ['roles' => ['api']]]],
        'resource_access as a string' => ['api'],
        'client entry is a string' => [['orders-api' => 'api']],
        'roles with non-strings' => [['orders-api' => ['roles' => [null, ['api'], 42]]]],
    ]);

    it('with api.clients set, both the role and an allowed azp are required', function () {
        config(['keycloak.api.clients' => 'reports-api']);
        $withRole = ['resource_access' => ['orders-api' => ['roles' => ['api']]]];

        ping(signedToken($this->key, $withRole))->assertOk();
        ping(signedToken($this->key, [...$withRole, 'azp' => 'stranger']))->assertUnauthorized();
        ping(signedToken($this->key))->assertUnauthorized();
    });
});

it('JWKS unavailable: 401 instead of 500, a warning without the token', function () {
    $this->jwks = null;
    Log::spy();

    ping(signedToken($this->key))->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'JWKS'));
});

it('caches the JWKS: the second request does not call Keycloak', function () {
    ping(signedToken($this->key))->assertOk();
    ping(signedToken($this->key))->assertOk();

    expect(jwksRequests())->toBe(1);
});

it('a new kid after rotation: the JWKS is refetched and the request passes', function () {
    ping(signedToken($this->key))->assertOk();

    $rotated = rsaKey('k2');
    $this->jwks = [$rotated['jwk']];

    ping(signedToken($rotated))->assertOk();

    expect(jwksRequests())->toBe(2);
});

it('JWKS unavailable during rotation: the next request refetches again instead of waiting a minute', function () {
    ping(signedToken($this->key))->assertOk();

    $rotated = rsaKey('k2');
    $this->jwks = null;
    ping(signedToken($rotated))->assertUnauthorized();

    $this->jwks = [$rotated['jwk']];
    ping(signedToken($rotated))->assertOk();

    expect(jwksRequests())->toBe(3);
});

it('a garbage kid refetches the JWKS at most once a minute', function () {
    ping(signedToken($this->key))->assertOk();

    $garbage = rsaKey('garbage');
    ping(signedToken($garbage))->assertUnauthorized();
    ping(signedToken($garbage))->assertUnauthorized();

    expect(jwksRequests())->toBe(2);

    $this->travel(61)->seconds();
    ping(signedToken($garbage))->assertUnauthorized();

    expect(jwksRequests())->toBe(3);
});

it('a non-JWT token yields null without HTTP requests', function (string $token) {
    ping($token)->assertUnauthorized();

    Http::assertNothingSent();
})->with([
    'Sanctum' => '1|abcdefghijklmnopqrstuvwxyz',
    'two segments' => 'abc.def',
    'no kid' => JWT::urlsafeB64Encode('{"alg":"RS256"}').'.e30.sig',
]);

it('without the Authorization header: 401 without HTTP requests', function () {
    $this->getJson('api/ping')->assertUnauthorized();

    Http::assertNothingSent();
});

describe('yields to the next guard', function () {
    it('a token for other passes auth:keycloak,other', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer other-token'])
            ->assertOk()
            ->assertJson(['id' => 'other']);
    });

    it('a Keycloak token passes auth:keycloak,other', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer '.signedToken($this->key)])
            ->assertOk()
            ->assertJson(['id' => 'reports-api']);
    });

    it('a broken JWT does not break the request but yields: other does not know it either, hence 401', function () {
        $this->getJson('api/either', ['Authorization' => 'Bearer '.unsignedToken('none', 'k1')])
            ->assertUnauthorized();
    });
});

it('the project replaces the guard user with its own class', function () {
    Keycloak::resolveApiClientUsing(fn (array $claims) => new GenericUser(['id' => 'api:'.$claims['azp']]));

    ping(signedToken($this->key))->assertOk()->assertJson(['class' => GenericUser::class, 'id' => 'api:reports-api']);
});

it('a custom resolver can refuse by returning null', function () {
    Keycloak::resolveApiClientUsing(fn (array $claims) => null);

    ping(signedToken($this->key))->assertUnauthorized();
});
