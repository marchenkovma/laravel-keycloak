<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Marchenkovma\LaravelKeycloak\Exceptions\ServiceTokenException;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

const TOKEN_URL = 'https://keycloak.test/realms/corp/protocol/openid-connect/token';

function fakeTokenEndpoint(int $expiresIn = 300): void
{
    // Каждый ответ с новым токеном: видно, взят он из кеша или запрошен заново
    $issued = 0;

    Http::fake([TOKEN_URL => function () use (&$issued, $expiresIn) {
        $issued++;

        return Http::response(['access_token' => "token-{$issued}", 'expires_in' => $expiresIn, 'token_type' => 'Bearer']);
    }]);
}

it('requests a client credentials token as a form with client_id and client_secret from the root config', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken())->toBe('token-1');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === TOKEN_URL
        && $request->method() === 'POST'
        && $request->isForm()
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'employees'
        && $request['client_secret'] === 'web-secret');
});

it('takes the credentials from the client_credentials section when it is filled', function () {
    config(['keycloak.client_credentials' => ['client_id' => 'portguard-api', 'client_secret' => 'api-secret']]);
    fakeTokenEndpoint();

    Keycloak::serviceToken();

    Http::assertSent(fn (HttpRequest $request) => $request['client_id'] === 'portguard-api' && $request['client_secret'] === 'api-secret');
});

it('arguments override the config', function () {
    fakeTokenEndpoint();

    Keycloak::serviceToken('reports-api', 'reports-secret');

    Http::assertSent(fn (HttpRequest $request) => $request['client_id'] === 'reports-api' && $request['client_secret'] === 'reports-secret');
});

it('client_id without a secret in client_credentials: an exception, not the web client secret', function () {
    config(['keycloak.client_credentials' => ['client_id' => 'portguard-api', 'client_secret' => null]]);
    Http::fake();

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class, 'client_secret');

    Http::assertNothingSent();
});

it('client_id as an argument without a secret: an exception, the config secret is not substituted', function () {
    Http::fake();

    expect(fn () => Keycloak::serviceToken('reports-api'))->toThrow(ServiceTokenException::class);

    Http::assertNothingSent();
});

it('caches the token: a repeated call does not go to Keycloak', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken())->toBe('token-1')
        ->and(Keycloak::serviceToken())->toBe('token-1');

    Http::assertSentCount(1);
});

it('requests again after expires_in minus 30 seconds', function () {
    fakeTokenEndpoint(expiresIn: 300);

    expect(Keycloak::serviceToken())->toBe('token-1');

    $this->travel(269)->seconds();
    expect(Keycloak::serviceToken())->toBe('token-1');

    $this->travel(2)->seconds();
    expect(Keycloak::serviceToken())->toBe('token-2');

    Http::assertSentCount(2);
});

it('tokens of different clients are cached separately', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken('a-api', 'a'))->toBe('token-1')
        ->and(Keycloak::serviceToken('b-api', 'b'))->toBe('token-2')
        ->and(Keycloak::serviceToken('a-api', 'a'))->toBe('token-1');
});

it('a token living shorter than the margin is not cached', function () {
    fakeTokenEndpoint(expiresIn: 20);

    Keycloak::serviceToken();
    Keycloak::serviceToken();

    Http::assertSentCount(2);
});

it('Keycloak refusal: an exception with the error from the response and without the secret', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Invalid client or Invalid client credentials'], 401)]);

    expect(fn () => Keycloak::serviceToken('portguard-api', 'super-secret-value'))
        ->toThrow(function (ServiceTokenException $e) {
            expect($e->getMessage())
                ->toContain('portguard-api')
                ->toContain('HTTP 401')
                ->toContain('unauthorized_client')
                ->not->toContain('super-secret-value');
        });
});

it('Keycloak unreachable: a package exception without the secret', function () {
    Http::fake([TOKEN_URL => fn () => throw new ConnectionException('cURL error 7: client_secret=super-secret-value')]);

    expect(fn () => Keycloak::serviceToken('portguard-api', 'super-secret-value'))
        ->toThrow(function (ServiceTokenException $e) {
            expect($e->getMessage())
                ->toContain(TOKEN_URL)
                ->not->toContain('super-secret-value')
                ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
        });
});

it('a response without access_token throws an exception', function () {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 300])]);

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class, 'нет access_token');
});

it('without client_id and client_secret: an exception without calling Keycloak', function () {
    config(['keycloak.client_id' => null, 'keycloak.client_secret' => null]);
    Http::fake();

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class);

    Http::assertNothingSent();
});
