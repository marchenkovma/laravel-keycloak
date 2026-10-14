<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;
use Marchenkovma\LaravelKeycloak\Tests\TestCase;
use Workbench\App\Models\User;

function actingAsKeycloakRole(string $role, ?string $idToken = 'fake-id-token'): TestCase
{
    return test()->actingAs(keycloakTestUser())->withSession([
        'keycloak' => ['role' => $role, 'id_token' => $idToken],
    ]);
}

describe('login', function () {
    it('отправляет на authorize-эндпоинт Keycloak с PKCE', function () {
        $location = $this->get(route('auth.login'))->assertRedirect()->headers->get('Location');

        expect($location)
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/auth')
            ->toContain('client_id=employees')
            ->toContain('response_type=code')
            ->toContain('code_challenge=')
            ->toContain('code_challenge_method=S256')
            ->toContain('%2Fauth%2Fcallback');
    });

    it('bypass-режим логинит фейкового пользователя с ролью из конфига', function () {
        config(['keycloak.web.bypass' => true, 'keycloak.web.bypass_role' => 'user']);

        $this->get(route('auth.login'))->assertRedirect(route('home'));

        $this->assertAuthenticated();
        expect(session('keycloak.role'))->toBe('user')
            ->and(session('keycloak.id_token'))->toBeNull()
            ->and(User::sole()->username)->toBe('bypass')
            ->and(User::sole()->name)->toBe(__('keycloak::messages.bypass_name'));
    });

    it('bypass игнорируется в production', function () {
        config(['keycloak.web.bypass' => true]);
        app()->detectEnvironment(fn () => 'production');

        $location = $this->get(route('auth.login'))->headers->get('Location');

        $this->assertGuest();
        expect($location)->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/auth');
    });
});

describe('callback', function () {
    it('создаёт пользователя, кладёт роль и id_token в сессию и ведёт на главную', function () {
        fakeKeycloakUser(groups: ['/apps/employees/user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));

        $this->assertAuthenticated();
        expect(session('keycloak.role'))->toBe('user')
            ->and(session('keycloak.id_token'))->toBe('fake-id-token');

        $user = User::sole();
        expect($user->username)->toBe('ivanov')
            ->and($user->name)->toBe('Иван Иванов')
            ->and($user->email)->toBe('ivanov@example.com')
            ->and($user->last_login_at)->not->toBeNull();
    });

    it('повторный вход обновляет пользователя, а не создаёт нового', function () {
        $old = new User;
        $old->forceFill(['username' => 'ivanov', 'name' => 'Старое Имя', 'email' => 'old@example.com'])->save();
        fakeKeycloakUser(groups: ['/apps/employees/user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));

        expect(User::sole()->name)->toBe('Иван Иванов')
            ->and(User::sole()->email)->toBe('ivanov@example.com');
    });

    it('ведёт на intended-адрес, если гость шёл на закрытую страницу', function () {
        fakeKeycloakUser(groups: ['/apps/employees/user']);

        $this->withSession(['url.intended' => url('/any-role')])
            ->get(route('auth.callback').'?code=x&state=y')
            ->assertRedirect(url('/any-role'));
    });

    it('роль по группе: первая совпавшая по приоритету', function () {
        fakeKeycloakUser(groups: ['/apps/employees/user', '/Administrators']);

        $this->get(route('auth.callback').'?code=x&state=y');

        expect(session('keycloak.role'))->toBe('admin');
    });

    it('роль по роли клиента (claim roles)', function () {
        fakeKeycloakUser(roles: ['admin']);

        $this->get(route('auth.callback').'?code=x&state=y');

        expect(session('keycloak.role'))->toBe('admin');
    });

    it('без групп и ролей приложения 403 с текстом из переводов, пользователь не создан', function () {
        fakeKeycloakUser(groups: ['/some/other/group'], roles: ['user']);

        $this->get(route('auth.callback').'?code=x&state=y')
            ->assertForbidden()
            ->assertSee(__('keycloak::messages.denied'));

        $this->assertGuest();
        expect(User::count())->toBe(0);
    });

    it('роль, которой нет в конфиге, входа не даёт (portguard: только admin)', function () {
        config(['keycloak.web.roles' => ['admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']]]]);
        fakeKeycloakUser(groups: ['/apps/employees/user'], roles: ['user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertForbidden();
    });

    it('устаревший state ведёт на on_failure с auth_notice, а не 500', function () {
        config(['keycloak.web.redirects.on_failure' => 'welcome']);
        staleKeycloakState();

        $this->get(route('auth.callback').'?state=old&code=x')
            ->assertRedirect(route('welcome'))
            ->assertSessionHas('auth_notice', 'session_expired');
    });

    it('устаревший state без on_failure ведёт на after_logout', function () {
        config(['keycloak.web.redirects.after_logout' => 'welcome']);
        staleKeycloakState();

        $this->get(route('auth.callback').'?state=old&code=x')->assertRedirect(route('welcome'));
    });

    it('пользователя создаёт реализация проекта, если она подменена', function () {
        app()->bind(ResolvesKeycloakUser::class, fn () => new class implements ResolvesKeycloakUser
        {
            public function resolve(Laravel\Socialite\Contracts\User $user): Authenticatable
            {
                return keycloakTestUser('custom-'.$user->getNickname());
            }

            public function bypass(): Authenticatable
            {
                return keycloakTestUser('custom-bypass');
            }
        });
        fakeKeycloakUser(groups: ['/apps/employees/user']);

        $this->get(route('auth.callback').'?code=x&state=y');

        expect(User::sole()->username)->toBe('custom-ivanov');
    });
});

describe('logout', function () {
    it('чистит сессию и уводит на end_session Keycloak с id_token_hint', function () {
        $response = actingAsKeycloakRole('user')->post(route('auth.logout'));

        $this->assertGuest();
        $response->assertSessionHas('auth_notice', 'signed_out');

        expect($response->headers->get('Location'))
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/logout')
            ->toContain('id_token_hint=fake-id-token')
            ->toContain('post_logout_redirect_uri='.urlencode(route('home')));
    });

    it('Inertia-logout получает 409 с X-Inertia-Location: иначе XHR упрётся в CORS', function () {
        $response = actingAsKeycloakRole('user')
            ->post(route('auth.logout'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409);

        $this->assertGuest();
        expect($response->headers->get('X-Inertia-Location'))
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/logout')
            ->toContain('id_token_hint=fake-id-token');
    });

    it('после выхода ведёт на after_logout из конфига', function () {
        config(['keycloak.web.redirects.after_logout' => 'welcome']);

        $response = actingAsKeycloakRole('admin')->post(route('auth.logout'));

        expect($response->headers->get('Location'))
            ->toContain('post_logout_redirect_uri='.urlencode(route('welcome')));
    });

    it('без id_token (bypass) ведёт сразу на after_logout мимо Keycloak', function () {
        actingAsKeycloakRole('admin', idToken: null)
            ->post(route('auth.logout'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('auth_notice', 'signed_out');

        $this->assertGuest();
    });
});

describe('роль в сессии', function () {
    it('Keycloak::role и hasRole читают роль из сессии', function () {
        actingAsKeycloakRole('admin')->get('/');

        expect(Keycloak::role())->toBe('admin')
            ->and(Keycloak::hasRole('admin'))->toBeTrue()
            ->and(Keycloak::hasRole('user', 'admin'))->toBeTrue()
            ->and(Keycloak::hasRole('user'))->toBeFalse();
    });

    it('без входа роли нет', function () {
        expect(Keycloak::role())->toBeNull()
            ->and(Keycloak::hasRole('admin'))->toBeFalse();
    });

    it('keycloak.role без аргументов пускает любую роль приложения', function () {
        actingAsKeycloakRole('user')->get('/any-role')->assertOk();
    });

    it('keycloak.role без роли в сессии отвечает 403', function () {
        $this->actingAs(keycloakTestUser())->get('/any-role')
            ->assertForbidden()
            ->assertSee(__('keycloak::messages.denied'));
    });

    it('keycloak.role:admin пускает admin и не пускает user', function () {
        actingAsKeycloakRole('admin')->get('/admin-only')->assertOk();

        app('auth')->forgetGuards();

        actingAsKeycloakRole('user')->get('/admin-only')->assertForbidden();
    });
});

describe('маршруты', function () {
    it('регистрируются с именами auth.* под префиксом из конфига', function () {
        expect(route('auth.login', absolute: false))->toBe('/auth/login')
            ->and(route('auth.callback', absolute: false))->toBe('/auth/callback')
            ->and(route('auth.logout', absolute: false))->toBe('/auth/logout')
            ->and(Route::getRoutes()->getByName('auth.login')?->gatherMiddleware())->toContain('web');
    });
});
