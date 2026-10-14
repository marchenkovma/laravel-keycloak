<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\SocialiteServiceProvider;
use Marchenkovma\LaravelKeycloak\KeycloakServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        // В проекте Socialite подключится автообнаружением, в Testbench явно
        return [
            SocialiteServiceProvider::class,
            SocialiteProvidersServiceProvider::class,
            KeycloakServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('keycloak.base_url', 'https://keycloak.test');
        $app['config']->set('keycloak.realm', 'corp');
        $app['config']->set('keycloak.client_id', 'employees');
        $app['config']->set('keycloak.client_secret', 'web-secret');

        // Роли как в employee-service: admin по группам, user по группе
        $app['config']->set('keycloak.web.roles', [
            'admin' => ['groups' => ['/Administrators', '/apps/employees/admin'], 'client_roles' => ['admin']],
            'user' => ['groups' => ['/apps/employees/user']],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/', fn () => 'home')->name('home');
            Route::get('welcome', fn () => 'welcome')->name('welcome');

            Route::get('any-role', fn () => 'ok')->middleware(['auth', 'keycloak.role']);
            Route::get('admin-only', fn () => 'ok')->middleware(['auth', 'keycloak.role:admin']);
        });
    }
}
