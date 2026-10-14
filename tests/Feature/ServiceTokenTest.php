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

it('запрашивает токен client credentials формой с client_id и client_secret из корня', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken())->toBe('token-1');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === TOKEN_URL
        && $request->method() === 'POST'
        && $request->isForm()
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'employees'
        && $request['client_secret'] === 'web-secret');
});

it('берёт учётные данные из секции client_credentials, если она заполнена', function () {
    config(['keycloak.client_credentials' => ['client_id' => 'portguard-api', 'client_secret' => 'api-secret']]);
    fakeTokenEndpoint();

    Keycloak::serviceToken();

    Http::assertSent(fn (HttpRequest $request) => $request['client_id'] === 'portguard-api' && $request['client_secret'] === 'api-secret');
});

it('аргументы перекрывают конфиг', function () {
    fakeTokenEndpoint();

    Keycloak::serviceToken('reports-api', 'reports-secret');

    Http::assertSent(fn (HttpRequest $request) => $request['client_id'] === 'reports-api' && $request['client_secret'] === 'reports-secret');
});

it('client_id без секрета в client_credentials: исключение, а не секрет веб-клиента', function () {
    config(['keycloak.client_credentials' => ['client_id' => 'portguard-api', 'client_secret' => null]]);
    Http::fake();

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class, 'client_secret');

    Http::assertNothingSent();
});

it('client_id аргументом без секрета: исключение, секрет из конфига не подставляется', function () {
    Http::fake();

    expect(fn () => Keycloak::serviceToken('reports-api'))->toThrow(ServiceTokenException::class);

    Http::assertNothingSent();
});

it('кеширует токен: повторный вызов не ходит в Keycloak', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken())->toBe('token-1')
        ->and(Keycloak::serviceToken())->toBe('token-1');

    Http::assertSentCount(1);
});

it('после expires_in минус 30 с запрашивает заново', function () {
    fakeTokenEndpoint(expiresIn: 300);

    expect(Keycloak::serviceToken())->toBe('token-1');

    $this->travel(269)->seconds();
    expect(Keycloak::serviceToken())->toBe('token-1');

    $this->travel(2)->seconds();
    expect(Keycloak::serviceToken())->toBe('token-2');

    Http::assertSentCount(2);
});

it('токены разных клиентов кешируются отдельно', function () {
    fakeTokenEndpoint();

    expect(Keycloak::serviceToken('a-api', 'a'))->toBe('token-1')
        ->and(Keycloak::serviceToken('b-api', 'b'))->toBe('token-2')
        ->and(Keycloak::serviceToken('a-api', 'a'))->toBe('token-1');
});

it('токен, живущий меньше запаса, не кешируется', function () {
    fakeTokenEndpoint(expiresIn: 20);

    Keycloak::serviceToken();
    Keycloak::serviceToken();

    Http::assertSentCount(2);
});

it('отказ Keycloak: исключение с error из ответа и без секрета', function () {
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

it('Keycloak недоступен: исключение пакета без секрета', function () {
    Http::fake([TOKEN_URL => fn () => throw new ConnectionException('cURL error 7: client_secret=super-secret-value')]);

    expect(fn () => Keycloak::serviceToken('portguard-api', 'super-secret-value'))
        ->toThrow(function (ServiceTokenException $e) {
            expect($e->getMessage())
                ->toContain(TOKEN_URL)
                ->not->toContain('super-secret-value')
                ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
        });
});

it('ответ без access_token даёт исключение', function () {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 300])]);

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class, 'нет access_token');
});

it('без client_id и client_secret исключение без похода в Keycloak', function () {
    config(['keycloak.client_id' => null, 'keycloak.client_secret' => null]);
    Http::fake();

    expect(fn () => Keycloak::serviceToken())->toThrow(ServiceTokenException::class);

    Http::assertNothingSent();
});
