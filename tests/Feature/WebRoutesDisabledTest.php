<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Marchenkovma\LaravelKeycloak\Http\Controllers\KeycloakController;
use Marchenkovma\LaravelKeycloak\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

// web.routes читается при загрузке провайдера, поэтому конфиг задаётся
// атрибутом Testbench до неё, а не config() внутри теста
final class WebRoutesDisabledTest extends TestCase
{
    protected function disableRoutes($app): void
    {
        $app['config']->set('keycloak.web.routes', false);
    }

    #[DefineEnvironment('disableRoutes')]
    public function test_login_routes_are_not_registered_when_disabled(): void
    {
        $this->assertFalse(Route::has('auth.login'));
    }

    #[DefineEnvironment('disableRoutes')]
    public function test_redirect_uri_comes_from_the_named_callback_route_of_the_project(): void
    {
        Route::middleware('web')->get('sso/login', [KeycloakController::class, 'login'])->name('auth.login');
        Route::middleware('web')->get('sso/return', [KeycloakController::class, 'callback'])->name('auth.callback');
        // name() после регистрации не обновляет поиск по именам
        $this->app['router']->getRoutes()->refreshNameLookups();

        $location = (string) $this->get('sso/login')->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('redirect_uri='.urlencode(url('sso/return')), $location);
    }

    #[DefineEnvironment('disableRoutes')]
    public function test_redirect_uri_falls_back_to_prefix_callback_without_the_named_route(): void
    {
        Route::middleware('web')->get('sso/login', [KeycloakController::class, 'login']);

        $location = (string) $this->get('sso/login')->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('redirect_uri='.urlencode(url('auth/callback')), $location);
    }

    public function test_middleware_alias_is_registered_regardless(): void
    {
        $this->assertArrayHasKey('keycloak.role', $this->app['router']->getMiddleware());
    }
}
