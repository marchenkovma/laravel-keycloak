<div align="center">
    <h1>Laravel Keycloak</h1>
</div>

<p align="center">
    <b>Русский</b> | <a href="README.en.md">English</a>
</p>

<p align="center">
    <a href="https://github.com/marchenkovma/laravel-keycloak/actions"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/marchenkovma/laravel-keycloak/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="LICENSE.md"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square"></a>
</p>

Keycloak для Laravel: вход пользователей через OIDC (Authorization Code + PKCE) с ролью приложения в сессии и guard для API, который проверяет токены сервисных клиентов.

- PHP 8.3+, Laravel 12 или 13.
- Sanctum и Inertia пакету не нужны.

## Установка

```bash
composer require marchenkovma/laravel-keycloak
```

Провайдер и фасад `Keycloak` подключаются автоматически. Драйвер `keycloak` для Socialite пакет регистрирует сам, поэтому:

- `services.keycloak` в `config/services.php` не нужен;
- слушатель `SocialiteWasCalled` в `AppServiceProvider` не нужен. Если он остался, удалите его: он перекроет драйвер пакета.

Опубликовать конфиг:

```bash
php artisan vendor:publish --tag=keycloak-config
```

## Переменные окружения

```dotenv
KEYCLOAK_BASE_URL=https://sso.example.com
KEYCLOAK_REALM=corp
KEYCLOAK_CLIENT_ID=employees
KEYCLOAK_CLIENT_SECRET=

# Локальная разработка без Keycloak: вход фейковым пользователем.
# В production не действует, что бы ни стояло в env
KEYCLOAK_BYPASS=false
KEYCLOAK_BYPASS_ROLE=admin
```

`KEYCLOAK_BASE_URL` — адрес, по которому приложение ходит в Keycloak. Он может быть внутренним.

Keycloak с сертификатом корпоративного CA: корневой сертификат должен быть доверенным в контейнере приложения, пакет его не настраивает.

## Вход пользователей

Пакет регистрирует маршруты с middleware `web`:

| Метод | Адрес | Имя |
|---|---|---|
| GET | `/auth/login` | `auth.login` |
| GET | `/auth/callback` | `auth.callback` |
| POST | `/auth/logout` | `auth.logout` |

Префикс задаётся в `keycloak.web.prefix`. В клиенте Keycloak разрешите redirect URI `https://<приложение>/auth/callback` и post logout redirect URI на страницу после выхода.

Чтобы зарегистрировать маршруты самостоятельно, поставьте `keycloak.web.routes = false` и укажите `Marchenkovma\LaravelKeycloak\Web\KeycloakController` (методы `login`, `callback`, `logout`).

### Роли

Роль приложения определяется при входе и хранится в сессии до выхода. Правила перечисляются по приоритету, берётся первая совпавшая:

- `groups` — полные пути групп (claim `groups`, mapper с включённым Full group path);
- `client_roles` — роли клиента (claim `roles`).

```php
// config/keycloak.php
'roles' => [
    'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
    'user' => ['groups' => ['/apps/employees/user']],
],
```

Если ни одно правило не совпало, вход завершается ответом 403.

```php
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

Keycloak::role();                  // 'admin' | 'user' | null
Keycloak::hasRole('admin');        // true, если роль одна из перечисленных

// Gate проект определяет сам
Gate::define('admin', fn ($user) => Keycloak::hasRole('admin'));
```

Middleware `keycloak.role`:

```php
Route::middleware(['auth', 'keycloak.role'])->group(...);        // любая роль приложения
Route::middleware(['auth', 'keycloak.role:admin'])->group(...);  // только admin
```

### Переадресации

```php
'redirects' => [
    'after_login' => 'home',    // имя маршрута; redirect()->intended() поверх
    'after_logout' => 'home',
    'on_failure' => null,       // устаревший state при входе; null — на after_logout
],
```

В сессии остаётся flash `auth_notice`, по которому страница может объяснить, что произошло:

- `session_expired` — форма входа провисела дольше жизни сессии;
- `signed_out` — пользователь вышел.

Выход идёт на end_session Keycloak с `id_token_hint`. Для Inertia-запроса (заголовок `X-Inertia`) пакет отвечает `409` с `X-Inertia-Location`, иначе обычным redirect.

### Что прописать в `bootstrap/app.php`

Гостя web-страницы нужно отправить на вход, а гостю API отдать JSON 401:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn (Request $request) => $request->is('api/*') ? null : route('auth.login'),
    );
})
->withExceptions(function (Exceptions $exceptions): void {
    // Истёкшая сессия в Inertia-XHR: 302 на /auth/login браузер повёл бы
    // внутри XHR дальше на Keycloak и упёрся в CORS
    $exceptions->render(function (AuthenticationException $e, Request $request) {
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('auth.login'));
        }
    });
})
```

### Свой пользователь

По умолчанию пользователь ищется в модели `auth.providers.users.model` по полю `username` (`preferred_username` Keycloak). Обновляются поля `name`, `email`, `last_login_at`. В таблице `users` должны быть эти колонки.

Другую логику задайте своей реализацией `ResolvesKeycloakUser`:

```php
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;

$this->app->bind(ResolvesKeycloakUser::class, MyKeycloakUsers::class);
```

### Тексты

Текст ответа 403 берётся из переводов `keycloak::messages.denied` (ru, en). Свой текст положите в `lang/vendor/keycloak/ru/messages.php` или опубликуйте файлы:

```bash
php artisan vendor:publish --tag=keycloak-lang
```

## API: токены сервисных клиентов

Guard `keycloak` проверяет `Authorization: Bearer <jwt>` — access-токен клиента Keycloak, полученный по client credentials. Подключите guard в `config/auth.php`:

```php
'guards' => [
    // ...
    'keycloak' => ['driver' => 'keycloak'],
],
```

```dotenv
KEYCLOAK_API_AUDIENCE=employee-service      # aud, который должен быть в токене
KEYCLOAK_API_CLIENTS=portguard-api,other    # разрешённые azp через запятую
KEYCLOAK_ISSUER=                             # пусто — {KEYCLOAK_BASE_URL}/realms/{realm}
KEYCLOAK_JWKS_CACHE_TTL=3600
```

`KEYCLOAK_ISSUER` нужен, если приложение ходит в Keycloak по внутреннему адресу. `iss` в токене — публичный адрес, по которому токен выдан, а ключи (JWKS) берутся по `KEYCLOAK_BASE_URL`.

```php
// только Keycloak
Route::middleware('auth:keycloak')->group(...);

// Keycloak или Sanctum: кто узнал токен, тот и пустил
Route::middleware('auth:keycloak,sanctum')->group(...);
```

На чужой или битый токен guard возвращает «нет пользователя», а не исключение. Поэтому `auth:keycloak,sanctum` доходит до Sanctum, а одиночный `auth:keycloak` даёт обычный 401. Токены Sanctum (`1|abc…`) guard отсеивает сразу, без запросов в Keycloak.

Что проверяется:

- подпись RS256 по JWKS realm-а (`none` и HS* отвергаются). Ключи кешируются, неизвестный `kid` перечитывает JWKS (ротация), но не чаще раза в минуту;
- `iss`, `aud` содержит `KEYCLOAK_API_AUDIENCE`, `azp` из `KEYCLOAK_API_CLIENTS`;
- `exp` обязателен, `exp` и `nbf` — с допуском 30 с (`keycloak.api.leeway`).

Keycloak недоступен — 401 и warning в логе. Отказ по токену — info с `kid`, `iss`, `azp` и причиной. Сам токен в лог не пишется.

Пользователь guard-а — `Marchenkovma\LaravelKeycloak\Api\KeycloakClient`:

```php
$client = $request->user();

$client->getAuthIdentifier();                 // azp, например 'portguard-api'
$client->claims;                              // все claims токена
$client->hasRole('employee-service', 'read'); // роль из resource_access
$client->hasScope('email');
```

Свой класс вместо него — в `AppServiceProvider::boot()`:

```php
Keycloak::resolveApiClientUsing(fn (array $claims) => new ApiClient(['name' => $claims['azp']]));
```

### Настройка клиента в Keycloak

1. Клиент, который будет ходить в API (например, `portguard-api`):
   - Client authentication ON (confidential);
   - Service accounts roles ON;
   - Standard flow и Direct access grants OFF.
2. Audience mapper на этом клиенте: Included Custom Audience = audience принимающего API (`employee-service`), Add to access token ON. Вместо этого можно взять client scope с таким mapper-ом.
3. Секрет клиента нужен только вызывающей стороне. Принимающему API он не нужен.

Проверка руками:

```bash
TOKEN=$(curl -s -d grant_type=client_credentials -d client_id=portguard-api -d client_secret=... \
  https://<keycloak>/realms/<realm>/protocol/openid-connect/token | jq -r .access_token)
curl -s -H "Authorization: Bearer $TOKEN" https://<api>/api/v1/...
```

## Разработка

```bash
composer test    # Larastan, Pint, покрытие типов, Pest
```

Изменения — в [CHANGELOG](CHANGELOG.md).

Нашли уязвимость? Не пишите в открытый issue, сообщите приватно: [Report a vulnerability](https://github.com/marchenkovma/laravel-keycloak/security/advisories/new). Подробнее — в [политике безопасности](.github/SECURITY.md).

## Лицензия

[MIT](LICENSE.md).
