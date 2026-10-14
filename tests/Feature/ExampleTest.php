<?php

declare(strict_types=1);

use Marchenkovma\LaravelKeycloak\Keycloak;

it('resolves the singleton', function () {
    expect(app(Keycloak::class))->toBeInstanceOf(Keycloak::class);
});

it('returns the same instance from the container', function () {
    expect(app(Keycloak::class))->toBe(app(Keycloak::class));
});

it('merges the package config', function () {
    expect(config('keycloak.placeholder'))->toBe('default');
});
