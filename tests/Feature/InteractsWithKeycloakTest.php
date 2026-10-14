<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Marchenkovma\LaravelKeycloak\Api\KeycloakClient;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

beforeEach(function () {
    config([
        'keycloak.api.audience' => 'employee-service',
        'keycloak.api.clients' => 'portguard-api,other-api',
        'auth.guards.keycloak' => ['driver' => 'keycloak'],
    ]);

    Route::get('api/whoami', function (Request $request) {
        /** @var KeycloakClient $client */
        $client = $request->user();

        return ['azp' => $client->getAuthIdentifier(), 'aud' => $client->claims['aud'], 'scope' => $client->claims['scope'] ?? null];
    })->middleware('auth:keycloak');
});

it('withKeycloakToken проходит guard keycloak с claims из конфига', function () {
    $this->withKeycloakToken()->getJson('api/whoami')
        ->assertOk()
        ->assertExactJson(['azp' => 'portguard-api', 'aud' => 'employee-service', 'scope' => null]);
});

it('withKeycloakToken дополняет и переопределяет claims', function () {
    $this->withKeycloakToken(['azp' => 'other-api', 'scope' => 'read'])->getJson('api/whoami')
        ->assertOk()
        ->assertJson(['azp' => 'other-api', 'scope' => 'read']);
});

it('null в claims убирает claim: без exp guard отказывает', function () {
    $this->withKeycloakToken(['exp' => null])->getJson('api/whoami')->assertUnauthorized();
});

it('токен клиента не из списка guard не пускает', function () {
    $this->withKeycloakToken(['azp' => 'stranger'])->getJson('api/whoami')->assertUnauthorized();
});

it('несколько запросов в одном тесте: каждый со своим токеном, JWKS качается один раз', function () {
    $this->withKeycloakToken()->getJson('api/whoami')->assertJson(['azp' => 'portguard-api']);
    $this->withKeycloakToken(['azp' => 'other-api'])->getJson('api/whoami')->assertJson(['azp' => 'other-api']);

    Http::assertSentCount(1);
});

it('keycloakToken отдаёт сам токен для своих заголовков', function () {
    $this->getJson('api/whoami', ['Authorization' => 'Bearer '.$this->keycloakToken()])->assertOk();
});

it('actingAsKeycloakUser входит пользователем с ролью в сессии', function () {
    $user = keycloakTestUser();

    $this->actingAsKeycloakUser($user, 'admin')->get('/admin-only')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(Keycloak::role())->toBe('admin');
});
