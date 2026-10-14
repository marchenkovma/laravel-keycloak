<div align="center">
    <h1>Laravel Keycloak</h1>
</div>

<p align="center">
    <a href="README.md">Русский</a> | <b>English</b>
</p>

<p align="center">
    <a href="https://github.com/marchenkovma/laravel-keycloak/actions"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/marchenkovma/laravel-keycloak/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="LICENSE.md"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square"></a>
</p>

Keycloak for Laravel: user login via OIDC (Authorization Code + PKCE) with the application role in the session, and an API guard that checks service client tokens.

- PHP 8.3+, Laravel 12 or 13.
- The package does not require Sanctum or Inertia.

## Installation

```bash
composer require marchenkovma/laravel-keycloak
```

The service provider and the `Keycloak` facade are auto-discovered. The package registers the `keycloak` Socialite driver itself, so:

- you do not need `services.keycloak` in `config/services.php`;
- you do not need a `SocialiteWasCalled` listener in `AppServiceProvider`. If one is left over, remove it: it would override the package driver.

Publish the config:

```bash
php artisan vendor:publish --tag=keycloak-config
```

## Environment

```dotenv
KEYCLOAK_BASE_URL=https://sso.example.com
KEYCLOAK_REALM=corp
KEYCLOAK_CLIENT_ID=employees
KEYCLOAK_CLIENT_SECRET=

# Local development without Keycloak: sign in as a fake user.
# Ignored in production whatever the env says
KEYCLOAK_BYPASS=false
KEYCLOAK_BYPASS_ROLE=admin
```

`KEYCLOAK_BASE_URL` is the address the application uses to reach Keycloak. It may be an internal one.

Keycloak with a corporate CA certificate: the root certificate must be trusted in the application container, the package does not configure it.

## User login

The package registers these routes with the `web` middleware:

| Method | Path | Name |
|---|---|---|
| GET | `/auth/login` | `auth.login` |
| GET | `/auth/callback` | `auth.callback` |
| POST | `/auth/logout` | `auth.logout` |

The prefix is set in `keycloak.web.prefix`. In the Keycloak client, allow the redirect URI `https://<app>/auth/callback` and a post logout redirect URI pointing to the after-logout page.

To register the routes yourself, set `keycloak.web.routes = false` and point them to `Marchenkovma\LaravelKeycloak\Web\KeycloakController` (`login`, `callback`, `logout`).

### Roles

The application role is resolved at login and kept in the session until logout. Rules are listed by priority, the first match wins:

- `groups`: full group paths (claim `groups`, mapper with Full group path on);
- `client_roles`: client roles (claim `roles`).

```php
// config/keycloak.php
'roles' => [
    'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
    'user' => ['groups' => ['/apps/employees/user']],
],
```

If no rule matches, login ends with a 403 response.

```php
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

Keycloak::role();                  // 'admin' | 'user' | null
Keycloak::hasRole('admin');        // true if the role is one of the given

// Gates are defined by the application
Gate::define('admin', fn ($user) => Keycloak::hasRole('admin'));
```

The `keycloak.role` middleware:

```php
Route::middleware(['auth', 'keycloak.role'])->group(...);        // any application role
Route::middleware(['auth', 'keycloak.role:admin'])->group(...);  // admin only
```

### Redirects

```php
'redirects' => [
    'after_login' => 'home',    // route name; redirect()->intended() on top
    'after_logout' => 'home',
    'on_failure' => null,       // stale state at login; null means after_logout
],
```

The session keeps an `auth_notice` flash so the page can explain what happened:

- `session_expired`: the login form stayed open longer than the session lived;
- `signed_out`: the user signed out.

Logout goes to the Keycloak end_session endpoint with `id_token_hint`. For an Inertia request (`X-Inertia` header) the package responds with `409` and `X-Inertia-Location`, otherwise with a regular redirect.

### What to add to `bootstrap/app.php`

Web guests should go to the login page, API guests should get a JSON 401:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn (Request $request) => $request->is('api/*') ? null : route('auth.login'),
    );
})
->withExceptions(function (Exceptions $exceptions): void {
    // Expired session in an Inertia XHR: the browser would follow a 302 to
    // /auth/login inside the XHR on to Keycloak and hit CORS
    $exceptions->render(function (AuthenticationException $e, Request $request) {
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('auth.login'));
        }
    });
})
```

### Custom user

By default the user is looked up in the `auth.providers.users.model` model by `username` (Keycloak `preferred_username`). The `name`, `email` and `last_login_at` fields are updated, and the `users` table needs these columns.

For different logic, bind your own `ResolvesKeycloakUser` implementation:

```php
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;

$this->app->bind(ResolvesKeycloakUser::class, MyKeycloakUsers::class);
```

### Texts

The 403 text comes from the `keycloak::messages.denied` translation (ru, en). Put your own text in `lang/vendor/keycloak/en/messages.php`, or publish the files:

```bash
php artisan vendor:publish --tag=keycloak-lang
```

## API: service client tokens

The `keycloak` guard checks `Authorization: Bearer <jwt>`, a Keycloak client access token obtained via client credentials. Add the guard to `config/auth.php`:

```php
'guards' => [
    // ...
    'keycloak' => ['driver' => 'keycloak'],
],
```

```dotenv
KEYCLOAK_API_AUDIENCE=employee-service      # aud the token must contain
KEYCLOAK_API_CLIENTS=portguard-api,other    # allowed azp, comma-separated
KEYCLOAK_ISSUER=                             # empty means {KEYCLOAK_BASE_URL}/realms/{realm}
KEYCLOAK_JWKS_CACHE_TTL=3600
```

Set `KEYCLOAK_ISSUER` when the application reaches Keycloak by an internal address. The token `iss` is the public address the token was issued at, while the keys (JWKS) are fetched from `KEYCLOAK_BASE_URL`.

```php
// Keycloak only
Route::middleware('auth:keycloak')->group(...);

// Keycloak or Sanctum: whichever recognises the token lets it in
Route::middleware('auth:keycloak,sanctum')->group(...);
```

On a foreign or broken token the guard returns "no user" instead of throwing. That is why `auth:keycloak,sanctum` falls through to Sanctum, while `auth:keycloak` alone gives a regular 401. Sanctum tokens (`1|abc…`) are skipped right away, without requests to Keycloak.

What is checked:

- an RS256 signature against the realm JWKS (`none` and HS* are rejected). Keys are cached; an unknown `kid` refetches the JWKS (key rotation), at most once a minute;
- `iss`, `aud` contains `KEYCLOAK_API_AUDIENCE`, `azp` is in `KEYCLOAK_API_CLIENTS`;
- `exp` is required, `exp` and `nbf` allow 30 s of clock skew (`keycloak.api.leeway`).

If Keycloak is unreachable: 401 and a warning in the log. A rejected token: info with `kid`, `iss`, `azp` and the reason. The token itself is never logged.

The guard user is `Marchenkovma\LaravelKeycloak\Api\KeycloakClient`:

```php
$client = $request->user();

$client->getAuthIdentifier();                 // azp, e.g. 'portguard-api'
$client->claims;                              // all token claims
$client->hasRole('employee-service', 'read'); // role from resource_access
$client->hasScope('email');
```

Use your own class instead in `AppServiceProvider::boot()`:

```php
Keycloak::resolveApiClientUsing(fn (array $claims) => new ApiClient(['name' => $claims['azp']]));
```

### Keycloak client setup

1. The client that calls the API (e.g. `portguard-api`):
   - Client authentication ON (confidential);
   - Service accounts roles ON;
   - Standard flow and Direct access grants OFF.
2. An audience mapper on that client: Included Custom Audience = the receiving API audience (`employee-service`), Add to access token ON. A client scope with such a mapper works too.
3. Only the calling side needs the client secret. The receiving API does not.

Manual check:

```bash
TOKEN=$(curl -s -d grant_type=client_credentials -d client_id=portguard-api -d client_secret=... \
  https://<keycloak>/realms/<realm>/protocol/openid-connect/token | jq -r .access_token)
curl -s -H "Authorization: Bearer $TOKEN" https://<api>/api/v1/...
```

## Development

```bash
composer test    # Larastan, Pint, type coverage, Pest
```

Changes are listed in the [CHANGELOG](CHANGELOG.md).

Found a vulnerability? Do not open a public issue, report it privately: [Report a vulnerability](https://github.com/marchenkovma/laravel-keycloak/security/advisories/new). See the [security policy](.github/SECURITY.md).

## License

[MIT](LICENSE.md).
