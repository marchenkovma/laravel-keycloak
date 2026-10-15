<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;
use Workbench\App\Models\User;

describe('login', function () {
    it('redirects to the Keycloak authorize endpoint with PKCE', function () {
        $location = $this->get(route('auth.login'))->assertRedirect()->headers->get('Location');

        expect($location)
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/auth')
            ->toContain('client_id=my-app')
            ->toContain('response_type=code')
            ->toContain('code_challenge=')
            ->toContain('code_challenge_method=S256')
            ->toContain('%2Fauth%2Fcallback');
    });

    it('bypass mode signs in a fake user with the role from the config', function () {
        config(['keycloak.web.bypass' => true, 'keycloak.web.bypass_role' => 'user']);

        $this->get(route('auth.login'))->assertRedirect(route('home'));

        $this->assertAuthenticated();
        expect(Keycloak::role())->toBe('user')
            ->and(Keycloak::idToken())->toBeNull()
            ->and(User::sole()->username)->toBe('bypass')
            ->and(User::sole()->name)->toBe(__('keycloak::messages.bypass_name'));
    });

    it('bypass passes the fake user through the project resolver like userinfo', function () {
        config(['keycloak.web.bypass' => true]);
        app()->bind(ResolvesKeycloakUser::class, fn () => new class implements ResolvesKeycloakUser
        {
            public function resolve(Laravel\Socialite\Contracts\User $user): Authenticatable
            {
                app()->instance('test.resolved-keycloak-user', $user);

                return keycloakTestUser('custom-'.$user->getNickname());
            }
        });

        $this->get(route('auth.login'))->assertRedirect(route('home'));

        /** @var Laravel\Socialite\Contracts\User $resolved */
        $resolved = app('test.resolved-keycloak-user');

        $this->assertAuthenticatedAs(User::sole());
        expect(User::sole()->username)->toBe('custom-bypass')
            ->and($resolved->getName())->toBe(__('keycloak::messages.bypass_name'))
            ->and($resolved->getEmail())->toBe('bypass@localhost');
    });

    it('bypass with an empty bypass_role grants no application role: keycloak.role answers 403', function () {
        config(['keycloak.web.bypass' => true, 'keycloak.web.bypass_role' => '']);

        $this->get(route('auth.login'))->assertRedirect(route('home'));

        $this->assertAuthenticated();
        expect(Keycloak::role())->toBeNull();
        $this->get('/any-role')->assertForbidden();
    });

    it('bypass is ignored in production', function () {
        config(['keycloak.web.bypass' => true]);
        app()->detectEnvironment(fn () => 'production');

        try {
            $location = $this->get(route('auth.login'))->headers->get('Location');

            $this->assertGuest();
            expect($location)->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/auth');
        } finally {
            // После теста Testbench откатывает миграции, а в production
            // migrate:rollback спрашивает подтверждение
            app()->detectEnvironment(fn () => 'testing');
        }
    });
});

describe('callback', function () {
    it('creates the user, stores the role and id_token in the session and redirects home', function () {
        fakeKeycloakUser(groups: ['/apps/my-app/user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));

        $this->assertAuthenticated();
        expect(Keycloak::role())->toBe('user')
            ->and(Keycloak::idToken())->toBe('fake-id-token');

        $user = User::sole();
        expect($user->username)->toBe('ivanov')
            ->and($user->name)->toBe('Иван Иванов')
            ->and($user->email)->toBe('ivanov@example.com')
            ->and($user->last_login_at)->not->toBeNull();
    });

    it('creates and updates the user without mass assignment: a guarded or strict model works too', function () {
        // Как в проекте с Model::shouldBeStrict(), где username не в $fillable
        Model::preventSilentlyDiscardingAttributes();
        fakeKeycloakUser(groups: ['/apps/my-app/user']);

        try {
            $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));
            expect(User::sole()->username)->toBe('ivanov');

            app('auth')->forgetGuards();
            $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));
            expect(User::count())->toBe(1)
                ->and(keycloakTestUser()->username)->toBe('test');
        } finally {
            Model::preventSilentlyDiscardingAttributes(false);
        }
    });

    it('a repeated login updates the user instead of creating a new one', function () {
        $old = new User;
        $old->forceFill(['username' => 'ivanov', 'name' => 'Старое Имя', 'email' => 'old@example.com'])->save();
        fakeKeycloakUser(groups: ['/apps/my-app/user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertRedirect(route('home'));

        expect(User::sole()->name)->toBe('Иван Иванов')
            ->and(User::sole()->email)->toBe('ivanov@example.com');
    });

    it('redirects to the intended URL when the guest came from a protected page', function () {
        fakeKeycloakUser(groups: ['/apps/my-app/user']);

        $this->withSession(['url.intended' => url('/any-role')])
            ->get(route('auth.callback').'?code=x&state=y')
            ->assertRedirect(url('/any-role'));
    });

    it('without application groups and roles: 403 with the translated text, no user created', function () {
        fakeKeycloakUser(groups: ['/some/other/group'], roles: ['user']);

        $this->get(route('auth.callback').'?code=x&state=y')
            ->assertForbidden()
            ->assertSee(__('keycloak::messages.denied'));

        $this->assertGuest();
        expect(User::count())->toBe(0);
    });

    it('a role missing from the config grants no access: only admin is configured', function () {
        config(['keycloak.web.roles' => ['admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']]]]);
        fakeKeycloakUser(groups: ['/apps/my-app/user'], roles: ['user']);

        $this->get(route('auth.callback').'?code=x&state=y')->assertForbidden();
    });

    it('a stale state redirects to on_failure with auth_notice instead of 500', function () {
        config(['keycloak.web.redirects.on_failure' => 'welcome']);
        staleKeycloakState();

        $this->get(route('auth.callback').'?state=old&code=x')
            ->assertRedirect(route('welcome'))
            ->assertSessionHas('auth_notice', 'session_expired');
    });

    it('a stale state without on_failure redirects to after_logout', function () {
        config(['keycloak.web.redirects.after_logout' => 'welcome']);
        staleKeycloakState();

        $this->get(route('auth.callback').'?state=old&code=x')->assertRedirect(route('welcome'));
    });

    it('Keycloak returned error instead of code: on_failure with auth_notice, Socialite is not called', function () {
        config(['keycloak.web.redirects.on_failure' => 'welcome']);
        Socialite::shouldReceive('driver')->never();

        $this->get(route('auth.callback').'?error=access_denied&error_description=User+cancelled&state=y')
            ->assertRedirect(route('welcome'))
            ->assertSessionHas('auth_notice', 'login_failed');

        $this->assertGuest();
    });

    it('a callback without code redirects to after_logout instead of 500', function () {
        Socialite::shouldReceive('driver')->never();

        $this->get(route('auth.callback').'?state=y')
            ->assertRedirect(route('home'))
            ->assertSessionHas('auth_notice', 'login_failed');
    });

    it('no login without preferred_username: otherwise all users would merge into one record', function () {
        fakeKeycloakUser(groups: ['/apps/my-app/user'], nickname: null);
        $this->withoutExceptionHandling();

        expect(fn () => $this->get(route('auth.callback').'?code=x&state=y'))
            ->toThrow(UnexpectedValueException::class, 'preferred_username');

        $this->assertGuest();
        expect(User::count())->toBe(0);
    });

    it('the project implementation creates the user when it is bound', function () {
        app()->bind(ResolvesKeycloakUser::class, fn () => new class implements ResolvesKeycloakUser
        {
            public function resolve(Laravel\Socialite\Contracts\User $user): Authenticatable
            {
                return keycloakTestUser('custom-'.$user->getNickname());
            }
        });
        fakeKeycloakUser(groups: ['/apps/my-app/user']);

        $this->get(route('auth.callback').'?code=x&state=y');

        expect(User::sole()->username)->toBe('custom-ivanov');
    });
});

describe('logout', function () {
    it('clears the session and redirects to the Keycloak end_session endpoint with id_token_hint', function () {
        $response = $this->actingAsKeycloakUser(keycloakTestUser(), 'user')->post(route('auth.logout'));

        $this->assertGuest();
        $response->assertSessionHas('auth_notice', 'signed_out');

        expect($response->headers->get('Location'))
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/logout')
            ->toContain('id_token_hint=fake-id-token')
            ->toContain('post_logout_redirect_uri='.urlencode(route('home')));
    });

    it('an Inertia logout gets 409 with X-Inertia-Location: otherwise the XHR would hit CORS', function () {
        $response = $this->actingAsKeycloakUser(keycloakTestUser(), 'user')
            ->post(route('auth.logout'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409);

        $this->assertGuest();
        expect($response->headers->get('X-Inertia-Location'))
            ->toStartWith('https://keycloak.test/realms/corp/protocol/openid-connect/logout')
            ->toContain('id_token_hint=fake-id-token');
    });

    it('redirects to after_logout from the config after logout', function () {
        config(['keycloak.web.redirects.after_logout' => 'welcome']);

        $response = $this->actingAsKeycloakUser(keycloakTestUser(), 'admin')->post(route('auth.logout'));

        expect($response->headers->get('Location'))
            ->toContain('post_logout_redirect_uri='.urlencode(route('welcome')));
    });

    it('without id_token (bypass) redirects straight to after_logout, skipping Keycloak', function () {
        config(['keycloak.web.bypass' => true]);
        $this->get(route('auth.login'))->assertRedirect(route('home'));

        $this->post(route('auth.logout'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('auth_notice', 'signed_out');

        $this->assertGuest();
    });
});

describe('role from claims', function () {
    it('takes the first match by priority from the groups claim', function () {
        expect(Keycloak::roleFor(['groups' => ['/apps/my-app/user', '/Administrators']]))->toBe('admin');
    });

    it('takes a client role from the roles claim', function () {
        expect(Keycloak::roleFor(['roles' => ['admin']]))->toBe('admin');
    });

    it('accepts a single group as a string: a mapper without multivalued', function () {
        expect(Keycloak::roleFor(['groups' => '/apps/my-app/user']))->toBe('user');
    });

    it('skips non-string values instead of failing', function () {
        expect(Keycloak::roleFor(['groups' => [['nested' => 'object'], 42, '/apps/my-app/user'], 'roles' => [null]]))->toBe('user');
    });

    it('no role without matching groups and roles', function (array $claims) {
        expect(Keycloak::roleFor($claims))->toBeNull();
    })->with([
        'other group and role' => [['groups' => ['/some/other/group'], 'roles' => ['user']]],
        'no claims' => [[]],
    ]);
});

describe('role in the session', function () {
    it('Keycloak::role and hasRole read the role from the session', function () {
        $this->actingAsKeycloakUser(keycloakTestUser(), 'admin')->get('/');

        expect(Keycloak::role())->toBe('admin')
            ->and(Keycloak::hasRole('admin'))->toBeTrue()
            ->and(Keycloak::hasRole('user', 'admin'))->toBeTrue()
            ->and(Keycloak::hasRole('user'))->toBeFalse();
    });

    it('no role without login', function () {
        expect(Keycloak::role())->toBeNull()
            ->and(Keycloak::hasRole('admin'))->toBeFalse();
    });

    it('keycloak.role without arguments allows any application role', function () {
        $this->actingAsKeycloakUser(keycloakTestUser(), 'user')->get('/any-role')->assertOk();
    });

    it('keycloak.role without a role in the session answers 403', function () {
        $this->actingAs(keycloakTestUser())->get('/any-role')
            ->assertForbidden()
            ->assertSee(__('keycloak::messages.denied'));
    });

    it('keycloak.role:admin allows admin and rejects user', function () {
        $this->actingAsKeycloakUser(keycloakTestUser(), 'admin')->get('/admin-only')->assertOk();

        app('auth')->forgetGuards();

        $this->actingAsKeycloakUser(keycloakTestUser(), 'user')->get('/admin-only')->assertForbidden();
    });
});

describe('routes', function () {
    it('are registered as auth.* under the prefix from the config', function () {
        expect(route('auth.login', absolute: false))->toBe('/auth/login')
            ->and(route('auth.callback', absolute: false))->toBe('/auth/callback')
            ->and(route('auth.logout', absolute: false))->toBe('/auth/logout')
            ->and(Route::getRoutes()->getByName('auth.login')?->gatherMiddleware())->toContain('web');
    });
});
