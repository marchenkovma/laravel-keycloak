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

it('несколько запросов в одном тесте: каждый со своим токеном', function () {
    $this->withKeycloakToken()->getJson('api/whoami')->assertJson(['azp' => 'portguard-api']);
    $this->withKeycloakToken(['azp' => 'other-api'])->getJson('api/whoami')->assertJson(['azp' => 'other-api']);
});

it('guard не ходит за JWKS по сети: Http::fake проекта, даже на все адреса, не мешает', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $this->withKeycloakToken()->getJson('api/whoami')->assertOk();

    Http::assertNothingSent();
});

it('свои заглушки Http::fake после withKeycloakToken тоже работают', function () {
    $this->withKeycloakToken();
    Http::fake(['https://employees.test/*' => Http::response(['ok' => true])]);

    $this->getJson('api/whoami')->assertOk();

    expect(Http::get('https://employees.test/api')->json('ok'))->toBeTrue();
    Http::assertSentCount(1);
});

it('кеш, который не хранит значения, даёт понятную ошибку вместо 401', function () {
    config(['cache.stores.null' => ['driver' => 'null'], 'cache.default' => 'null']);

    expect(fn () => $this->withKeycloakToken())->toThrow(RuntimeException::class, 'cache');
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
