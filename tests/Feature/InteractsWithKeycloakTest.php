<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Marchenkovma\LaravelKeycloak\Api\KeycloakClient;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

beforeEach(function () {
    config([
        'keycloak.api.audience' => 'orders-api',
        'keycloak.api.clients' => 'reports-api,other-api',
        'auth.guards.keycloak' => ['driver' => 'keycloak'],
    ]);

    Route::get('api/whoami', function (Request $request) {
        /** @var KeycloakClient $client */
        $client = $request->user();

        return ['azp' => $client->getAuthIdentifier(), 'aud' => $client->claims['aud'], 'scope' => $client->claims['scope'] ?? null];
    })->middleware('auth:keycloak');
});

it('withKeycloakToken passes the keycloak guard with claims from the config', function () {
    $this->withKeycloakToken()->getJson('api/whoami')
        ->assertOk()
        ->assertExactJson(['azp' => 'reports-api', 'aud' => 'orders-api', 'scope' => null]);
});

it('withKeycloakToken adds and overrides claims', function () {
    $this->withKeycloakToken(['azp' => 'other-api', 'scope' => 'read'])->getJson('api/whoami')
        ->assertOk()
        ->assertJson(['azp' => 'other-api', 'scope' => 'read']);
});

it('null in claims removes the claim: without exp the guard refuses', function () {
    $this->withKeycloakToken(['exp' => null])->getJson('api/whoami')->assertUnauthorized();
});

it('the guard rejects a token of a client outside the list', function () {
    $this->withKeycloakToken(['azp' => 'stranger'])->getJson('api/whoami')->assertUnauthorized();
});

it('several requests in one test, each with its own token', function () {
    $this->withKeycloakToken()->getJson('api/whoami')->assertJson(['azp' => 'reports-api']);
    $this->withKeycloakToken(['azp' => 'other-api'])->getJson('api/whoami')->assertJson(['azp' => 'other-api']);
});

it('the guard does not fetch the JWKS over the network: a project Http::fake, even a catch-all, does not interfere', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $this->withKeycloakToken()->getJson('api/whoami')->assertOk();

    Http::assertNothingSent();
});

it('project Http::fake stubs registered after withKeycloakToken keep working', function () {
    $this->withKeycloakToken();
    Http::fake(['https://orders.test/*' => Http::response(['ok' => true])]);

    $this->getJson('api/whoami')->assertOk();

    expect(Http::get('https://orders.test/api')->json('ok'))->toBeTrue();
    Http::assertSentCount(1);
});

it('a cache store that keeps no values gives a clear error instead of 401', function () {
    config(['cache.stores.null' => ['driver' => 'null'], 'cache.default' => 'null']);

    expect(fn () => $this->withKeycloakToken())->toThrow(RuntimeException::class, 'cache');
});

it('keycloakToken returns the raw token for custom headers', function () {
    $this->getJson('api/whoami', ['Authorization' => 'Bearer '.$this->keycloakToken()])->assertOk();
});

it('actingAsKeycloakUser signs the user in with a role in the session', function () {
    $user = keycloakTestUser();

    $this->actingAsKeycloakUser($user, 'admin')->get('/admin-only')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(Keycloak::role())->toBe('admin')
        ->and(Keycloak::idToken())->toBe('fake-id-token');
});
