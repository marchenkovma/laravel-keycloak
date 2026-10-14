<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Marchenkovma\LaravelKeycloak\Keycloak;
use Marchenkovma\LaravelKeycloak\KeycloakServiceProvider;
use SocialiteProviders\Keycloak\Provider as KeycloakProvider;

it('merges the package config under the keycloak key', function () {
    expect(config('keycloak.web.prefix'))->toBe('auth')
        ->and(config('keycloak.api.leeway'))->toBe(30)
        ->and(config('keycloak.realm'))->toBe('corp');
});

it('publishes the config with the keycloak-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(KeycloakServiceProvider::class, 'keycloak-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('keycloak.php'));
});

it('resolves the Keycloak service as a singleton', function () {
    expect(app(Keycloak::class))->toBe(app(Keycloak::class));
});

it('registers the keycloak Socialite driver from config/keycloak.php', function () {
    $driver = Socialite::driver('keycloak');

    expect($driver)->toBeInstanceOf(KeycloakProvider::class);

    $url = $driver->stateless()->redirect()->getTargetUrl();

    expect($url)->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/auth?')
        ->and($url)->toContain('client_id=employees')
        ->and($url)->toContain('redirect_uri='.urlencode(url('/auth/callback')));
});

it('reads the config when the driver is built, not at boot', function () {
    config(['keycloak.realm' => 'other']);

    $url = Socialite::driver('keycloak')->stateless()->redirect()->getTargetUrl();

    expect($url)->toStartWith('https://keycloak.test/realms/other/');
});

it('takes redirect_uri from the registered auth.callback route, not from web.prefix at build time', function () {
    config(['keycloak.web.prefix' => 'sso']);

    $url = Socialite::driver('keycloak')->stateless()->redirect()->getTargetUrl();

    expect($url)->toContain('redirect_uri='.urlencode(url('/auth/callback')));
});

it('does not need services.keycloak', function () {
    expect(config('services.keycloak'))->toBeNull();
});

it('translates the denied message', function (string $locale, string $start) {
    app()->setLocale($locale);

    expect(__('keycloak::messages.denied'))->toStartWith($start);
})->with([
    'ru' => ['ru', 'Нет доступа'],
    'en' => ['en', 'Access denied'],
]);

it('publishes the translations with the keycloak-lang tag', function () {
    $paths = ServiceProvider::pathsToPublish(KeycloakServiceProvider::class, 'keycloak-lang');

    expect(array_values($paths))->toBe([lang_path('vendor/keycloak')]);
});

it('lets the project override the denied message', function () {
    app('translator')->addLines(['messages.denied' => 'Свой текст'], 'ru', 'keycloak');
    app()->setLocale('ru');

    expect(__('keycloak::messages.denied'))->toBe('Свой текст');
});
