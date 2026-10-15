<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;
use Marchenkovma\LaravelKeycloak\Keycloak;
use SocialiteProviders\Keycloak\Provider as KeycloakProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * OIDC-флоу против Keycloak: приложение работает как confidential-клиент,
 * Authorization Code + PKCE. Токены в браузер не попадают.
 *
 * Socialite вызывается через фасад цепочкой driver('keycloak')->enablePKCE():
 * так её мокают тесты проектов.
 */
class KeycloakController
{
    public function __construct(
        private readonly Keycloak $keycloak,
        private readonly ResolvesKeycloakUser $users,
        private readonly Config $config,
    ) {}

    public function login(Request $request): SymfonyRedirectResponse
    {
        if ($this->keycloak->bypassEnabled()) {
            return $this->signIn($request, $this->users->resolve($this->bypassUser()), $this->stringConfig('web.bypass_role'), null);
        }

        /** @var KeycloakProvider $driver */
        $driver = Socialite::driver('keycloak');

        return $driver->enablePKCE()->redirect();
    }

    public function callback(Request $request): SymfonyRedirectResponse
    {
        // Keycloak вернул error вместо code (пользователь отменил вход, прервал
        // required action): обмен кода на токен не состоится, Socialite не нужен
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->failed('login_failed');
        }

        /** @var KeycloakProvider $driver */
        $driver = Socialite::driver('keycloak');

        try {
            $oidcUser = $driver->enablePKCE()->user();
        } catch (InvalidStateException) {
            // state из ответа Keycloak не нашёлся в сессии: форму входа
            // держали открытой дольше жизни сессии или обновили callback
            return $this->failed('session_expired');
        }

        $role = $this->keycloak->roleFor($oidcUser->getRaw());

        abort_if($role === null, 403, __('keycloak::messages.denied'));

        // id_token нужен для id_token_hint при выходе; на фронт не отдаётся
        $idToken = $oidcUser->accessTokenResponseBody['id_token'] ?? null;

        return $this->signIn($request, $this->users->resolve($oidcUser), $role, is_string($idToken) ? $idToken : null);
    }

    public function logout(Request $request): RedirectResponse|Response
    {
        $idToken = $this->keycloak->idToken();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Доживёт в новой сессии до страницы после выхода
        $request->session()->flash('auth_notice', 'signed_out');

        $afterLogout = route($this->stringConfig('web.redirects.after_logout'));

        // Без id_token (bypass) SSO-сессии в Keycloak нет, на end_session незачем
        if ($idToken === null) {
            return redirect()->to($afterLogout);
        }

        /** @var KeycloakProvider $driver */
        $driver = Socialite::driver('keycloak');

        $url = $driver->getLogoutUrl($afterLogout, $this->stringConfig('client_id'), $idToken);

        // Для Inertia не 302: за ним XHR пошёл бы на Keycloak сам и упёрся
        // в CORS. 409 + X-Inertia-Location делает то же, что Inertia::location
        if ($request->hasHeader('X-Inertia')) {
            return new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        return redirect()->away($url);
    }

    private function signIn(Request $request, Authenticatable $user, string $role, ?string $idToken): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $this->keycloak->storeSignIn($role, $idToken);

        return redirect()->intended(route($this->stringConfig('web.redirects.after_login')));
    }

    // Bypass приходит в резолвер так же, как userinfo Keycloak: своя
    // реализация ResolvesKeycloakUser обслуживает его без отдельного метода
    private function bypassUser(): SocialiteUser
    {
        $name = __('keycloak::messages.bypass_name');
        $attributes = [
            'preferred_username' => 'bypass',
            'name' => is_string($name) ? $name : 'bypass',
            'email' => 'bypass@localhost',
        ];

        return (new OAuthUser)->setRaw($attributes)->map([
            'id' => 'bypass',
            'nickname' => $attributes['preferred_username'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ]);
    }

    // Страница on_failure, без неё after_logout, с flash auth_notice для текста причины
    private function failed(string $notice): RedirectResponse
    {
        return redirect()
            ->route($this->stringConfig('web.redirects.on_failure') ?: $this->stringConfig('web.redirects.after_logout'))
            ->with('auth_notice', $notice);
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get("keycloak.{$key}");

        return is_string($value) ? $value : '';
    }
}
