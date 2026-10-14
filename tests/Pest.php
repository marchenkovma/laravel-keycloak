<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Marchenkovma\LaravelKeycloak\Tests\TestCase;
use SocialiteProviders\Manager\OAuth2\User as OidcUser;
use Workbench\App\Models\User;

uses(TestCase::class)->in(__DIR__);

/**
 * Socialite стоит на границе с сетью Keycloak и мокается целиком той же цепочкой,
 * что в тестах проектов.
 *
 * @param  list<string>  $groups
 * @param  list<string>  $roles
 */
function fakeKeycloakUser(array $groups = [], array $roles = []): void
{
    $oidcUser = (new OidcUser)->map([
        'id' => 'kc-uuid-1',
        'nickname' => 'ivanov',
        'name' => 'Иван Иванов',
        'email' => 'ivanov@example.com',
    ])->setRaw([
        'preferred_username' => 'ivanov',
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
    $user = User::query()->firstOrNew(['username' => $username]);
    $user->forceFill(['username' => $username, 'name' => 'Тестовый Пользователь', 'email' => "{$username}@example.com"])->save();

    return $user;
}
