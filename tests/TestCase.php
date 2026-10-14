<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\SocialiteServiceProvider;
use Marchenkovma\LaravelKeycloak\KeycloakServiceProvider;
use Marchenkovma\LaravelKeycloak\Testing\InteractsWithKeycloak;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;
use Workbench\App\Models\User;

use function Orchestra\Testbench\workbench_path;

abstract class TestCase extends Orchestra
{
    use InteractsWithKeycloak;
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

        // Testbench читает workbench/.env и в тестах. Тесты от него не зависят:
        // файловая база демо ломается от параллельных процессов, файловый кеш
        // переносил бы JWKS и замок перечитывания из теста в тест
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('keycloak.base_url', 'https://keycloak.test');
        $app['config']->set('keycloak.realm', 'corp');
        $app['config']->set('keycloak.client_id', 'employees');
        $app['config']->set('keycloak.client_secret', 'web-secret');
        $app['config']->set('keycloak.web.bypass', false);

        // Роли как в employee-service: admin по группам, user по группе
        $app['config']->set('keycloak.web.roles', [
            'admin' => ['groups' => ['/Administrators', '/apps/employees/admin'], 'client_roles' => ['admin']],
            'user' => ['groups' => ['/apps/employees/user']],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Те же миграции, что у workbench (testbench.yaml): одно тестовое приложение
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
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
