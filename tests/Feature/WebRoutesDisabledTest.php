<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Tests\Feature;

use Illuminate\Support\Facades\Route;
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

    public function test_middleware_alias_is_registered_regardless(): void
    {
        $this->assertArrayHasKey('keycloak.role', $this->app['router']->getMiddleware());
    }
}
