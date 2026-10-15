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

- PHP 8.4+ с расширением openssl, Laravel 13.
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
KEYCLOAK_CLIENT_ID=my-app
KEYCLOAK_CLIENT_SECRET=

# Локальная разработка без Keycloak: вход фейковым пользователем.
# В production не действует, что бы ни стояло в env
KEYCLOAK_BYPASS=false
KEYCLOAK_BYPASS_ROLE=admin
```

`KEYCLOAK_BASE_URL` задаёт адрес, по которому приложение ходит в Keycloak. Он может быть внутренним.

Keycloak с сертификатом корпоративного CA: корневой сертификат должен быть доверенным в контейнере приложения, пакет его не настраивает.

## Вход пользователей

Пакет регистрирует маршруты с middleware `web`:

| Метод | Адрес | Имя |
|---|---|---|
| GET | `/auth/login` | `auth.login` |
| GET | `/auth/callback` | `auth.callback` |
| POST | `/auth/logout` | `auth.logout` |

Префикс задаётся в `keycloak.web.prefix`.

Чтобы зарегистрировать маршруты самостоятельно, поставьте `keycloak.web.routes = false` и укажите `Marchenkovma\LaravelKeycloak\Http\Controllers\KeycloakController` (методы `login`, `callback`, `logout`). Маршрут callback назовите `auth.callback`: из него пакет берёт `redirect_uri` для Keycloak. Без такого имени используется `{prefix}/callback`.

### Настройка клиента для входа

1. Клиент приложения (например, `my-app`):
   - Client authentication ON (confidential), Standard flow ON;
   - в Advanced: Proof Key for Code Exchange Code Challenge Method = S256;
   - Valid redirect URIs: `https://<приложение>/auth/callback`;
   - Valid post logout redirect URIs: адрес страницы после выхода (`after_logout`).
2. Mapper-ы на клиенте или в его dedicated scope. Socialite читает claims из userinfo, поэтому у обоих включён **Add to userinfo**:
   - Group Membership: Token Claim Name `groups`, Full group path ON;
   - User Client Role: Client ID = этот клиент, Token Claim Name `resource_access.${client_id}.roles`, Multivalued ON.

Вместо своего mapper-а ролей можно включить Add to userinfo у mapper-а `client roles` в client scope `roles`: он пишет роли в тот же `resource_access`. Scope общий для realm-а, поэтому роли попадут в userinfo всех клиентов, где он подключён.

Без Add to userinfo групп и ролей в ответе нет, и любой вход закончится 403. Что пришло на самом деле, видно в ответе `/realms/{realm}/protocol/openid-connect/userinfo` или в Client scopes, вкладка Evaluate, Generated user info.

### Роли

Роль приложения определяется при входе и хранится в сессии до выхода. Правила перечисляются по приоритету, берётся первая совпавшая:

- `groups`: полные пути групп (claim `groups`, mapper с включённым Full group path);
- `client_roles`: роли клиента `keycloak.client_id` из `resource_access.{client_id}.roles`. Если записи этого клиента в `resource_access` нет, берётся плоский claim `roles` (mapper User Client Role с Token Claim Name `roles`).

```php
// config/keycloak.php
'roles' => [
    'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
    'user' => ['groups' => ['/apps/my-app/user']],
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
    'on_failure' => null,       // устаревший state при входе; null: на after_logout
],
```

В сессии остаётся flash `auth_notice`, по которому страница может объяснить, что произошло:

- `session_expired`: форма входа провисела дольше жизни сессии;
- `login_failed`: Keycloak вернул `error` вместо кода, например пользователь отменил вход;
- `signed_out`: пользователь вышел.

Выход идёт на end_session Keycloak с `id_token_hint`. Для Inertia-запроса (заголовок `X-Inertia`) пакет отвечает `409` с `X-Inertia-Location`, иначе обычным redirect.

### Страница входа

Если `after_logout` ведёт на маршрут под `auth`, гостя сразу уводит на `auth/login`, а оттуда на форму Keycloak. Человек, который только что вышел, видит форму входа без спроса, а если она провисит дольше жизни сессии, вход закончится `session_expired`. Лучше вести выход и неудавшийся вход на публичную страницу с кнопкой «Войти».

Для проектов на Inertia и Vue (стартовый набор Laravel: shadcn-vue, Wayfinder, `AppLogoIcon`) в пакете есть заготовка такой страницы:

```bash
php artisan vendor:publish --tag=keycloak-welcome
```

Команда копирует `app/Http/Controllers/Auth/WelcomeController.php` и `resources/js/pages/Welcome.vue`. После публикации это код проекта: пакет его не обновляет, правьте под себя. Дальше маршрут, переадресации и layout:

```php
// routes/web.php
use App\Http\Controllers\Auth\WelcomeController;

Route::get('welcome', WelcomeController::class)->middleware('guest')->name('welcome');
```

```php
// config/keycloak.php
'after_logout' => 'welcome',
'on_failure' => 'welcome',
```

Если в `resources/js/app.ts` layout по умолчанию задан для всех страниц, исключите из него `Welcome`, иначе страница входа откроется с боковым меню:

```ts
// resources/js/app.ts
layout: (name) => (name === 'Welcome' ? null : AppLayout),
```

Тексты страницы берутся из `keycloak::messages.welcome` (ru, en), сообщение о выходе подставляет `config('app.name')`.

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

В bypass-режиме в тот же `resolve()` приходит фейковый пользователь: username `bypass`, имя из `keycloak::messages.bypass_name`, почта `bypass@localhost`. Отдельно обрабатывать bypass не нужно.

### Тексты

Текст ответа 403 берётся из переводов `keycloak::messages.denied`, тексты страницы входа из `keycloak::messages.welcome` (ru, en). Свои тексты положите в `lang/vendor/keycloak/ru/messages.php` или опубликуйте файлы:

```bash
php artisan vendor:publish --tag=keycloak-lang
```

## API: токены сервисных клиентов

Guard `keycloak` проверяет `Authorization: Bearer <jwt>`, то есть access-токен клиента Keycloak, полученный по client credentials. Подключите guard в `config/auth.php`:

```php
'guards' => [
    // ...
    'keycloak' => ['driver' => 'keycloak'],
],
```

```dotenv
KEYCLOAK_API_AUDIENCE=orders-api            # aud в токене, он же client id API в Keycloak
KEYCLOAK_API_ROLE=api                        # роль клиента-API, которая даёт доступ
KEYCLOAK_API_CLIENTS=                        # необязательно: разрешённые azp через запятую
KEYCLOAK_ISSUER=                             # пусто: {KEYCLOAK_BASE_URL}/realms/{realm}
KEYCLOAK_JWKS_CACHE_TTL=3600
```

`KEYCLOAK_ISSUER` нужен, если приложение ходит в Keycloak по внутреннему адресу. `iss` в токене содержит публичный адрес, по которому токен выдан, а ключи (JWKS) берутся по `KEYCLOAK_BASE_URL`.

```php
// только Keycloak
Route::middleware('auth:keycloak')->group(...);

// Keycloak или Sanctum: кто узнал токен, тот и пустил
Route::middleware('auth:keycloak,sanctum')->group(...);
```

На чужой или битый токен guard возвращает «нет пользователя», а не исключение. Поэтому `auth:keycloak,sanctum` доходит до Sanctum, а одиночный `auth:keycloak` даёт обычный 401. Токены Sanctum (`1|abc…`) guard отсеивает сразу, без запросов в Keycloak.

Что проверяется:

- подпись RS256 по JWKS realm-а (`none` и HS* отвергаются). Ключи кешируются, неизвестный `kid` перечитывает JWKS (ротация), но не чаще раза в минуту;
- `iss`, `aud` содержит `KEYCLOAK_API_AUDIENCE`;
- `exp` обязателен, `exp` и `nbf` проверяются с допуском 30 с (`keycloak.api.leeway`);
- правило доступа по роли и списку `azp`:

| `KEYCLOAK_API_ROLE` | `KEYCLOAK_API_CLIENTS` | Кого пускает |
|---|---|---|
| задана | пусто | токен с ролью в `resource_access.{KEYCLOAK_API_AUDIENCE}.roles` |
| задана | задан | токен с ролью и `azp` из списка |
| пусто | задан | токен с `azp` из списка |
| пусто | пусто | никого: одного `aud` для доступа мало |

Роль ищется под ключом `KEYCLOAK_API_AUDIENCE`, поэтому он должен совпадать с client id API в Keycloak. Роль одного клиента не открывает API другого.

Токен проверяется локально, без introspection: снятая в Keycloak роль перестаёт действовать, когда истечёт `exp` уже выданного токена (в Keycloak по умолчанию 5 минут).

Если Keycloak недоступен, запрос получает 401, а в лог пишется warning. При отказе по токену в лог идёт info с `kid`, `iss`, `azp` и причиной. Сам токен в лог не пишется.

Пользователь guard-а: `Marchenkovma\LaravelKeycloak\Api\KeycloakClient`.

```php
$client = $request->user();

$client->getAuthIdentifier();                 // azp, например 'reports-api'
$client->claims;                              // все claims токена
$client->hasRole('orders-api', 'read');       // роль из resource_access
$client->hasScope('email');
```

Свой класс вместо него задаётся в `AppServiceProvider::boot()`:

```php
Keycloak::resolveApiClientUsing(fn (array $claims) => new ApiClient(['name' => $claims['azp']]));
```

### Токен для исходящих запросов

`Keycloak::serviceToken()` получает access-токен client credentials, чтобы ходить в другие сервисы от имени клиента:

```php
use Marchenkovma\LaravelKeycloak\Facades\Keycloak;

Http::withToken(Keycloak::serviceToken())->get('https://orders.example.com/api/v1/orders');
```

Токен кешируется до `expires_in` минус 30 с, повторные вызовы в Keycloak не ходят. Учётные данные берутся в таком порядке:

1. аргументы: `Keycloak::serviceToken('reports-api', $secret)`;
2. `KEYCLOAK_SERVICE_CLIENT_ID` и `KEYCLOAK_SERVICE_CLIENT_SECRET`, если вызовы идут от отдельного клиента (например, `reports-api`), а вход пользователей от другого;
3. `KEYCLOAK_CLIENT_ID` и `KEYCLOAK_CLIENT_SECRET`.

Пара берётся целиком из одного источника. Если задан только `KEYCLOAK_SERVICE_CLIENT_ID` без секрета или только первый аргумент, бросается исключение: id одного клиента не уйдёт с секретом другого.

Если Keycloak отказал или недоступен, бросается `Marchenkovma\LaravelKeycloak\Exceptions\ServiceTokenException`. В тексте исключения есть клиент, адрес и `error` из ответа Keycloak, секрета там нет.

### Настройка клиента в Keycloak

Основной способ: доступ по роли. Новый клиент получает доступ выдачей роли, отзыв делается снятием роли, перевыкладывать API не нужно.

1. На клиенте принимающего API (`orders-api`) в Client roles заведите роль `api`.
2. Клиент, который будет ходить в API (например, `reports-api`):
   - Client authentication ON (confidential);
   - Service accounts roles ON;
   - Standard flow и Direct access grants OFF;
   - на вкладке Service account roles выдайте роль `orders-api`: `api`.
3. Client scope `roles` должен быть среди Default client scopes вызывающего клиента (по умолчанию он там есть). Его mapper Audience Resolve сам добавляет `orders-api` в `aud`, раз в токене есть роли этого клиента, а у mapper-а `client roles` должен быть включён Add to access token. Отдельный audience mapper не нужен.
4. Проверка: Clients, `reports-api`, Client scopes, Evaluate, пользователь `service-account-reports-api`, Generated access token. В токене должны быть `aud` с `orders-api` и `resource_access.orders-api.roles` с `api`.

Второй способ: список `azp`. На вызывающем клиенте ставится Audience mapper (Included Custom Audience = `orders-api`, Add to access token ON), а его client id добавляется в `KEYCLOAK_API_CLIENTS` принимающего API. Каждый новый клиент требует правки `.env` и перевыкладки API.

Секрет клиента нужен только вызывающей стороне. Принимающему API он не нужен.

Проверка руками:

```bash
TOKEN=$(curl -s -d grant_type=client_credentials -d client_id=reports-api -d client_secret=... \
  https://<keycloak>/realms/<realm>/protocol/openid-connect/token | jq -r .access_token)
curl -s -H "Authorization: Bearer $TOKEN" https://<api>/api/v1/...
```

## Тесты в проекте

Трейт `InteractsWithKeycloak` подключается в `TestCase` проекта:

```php
use Marchenkovma\LaravelKeycloak\Testing\InteractsWithKeycloak;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithKeycloak;
}
```

```php
// API: настоящий RS256-токен, guard проверяет его полностью, без Keycloak
$this->withKeycloakToken()->getJson('/api/v1/orders')->assertOk();
$this->withKeycloakToken(['resource_access' => null])->getJson('/api/v1/orders')->assertUnauthorized();
$this->withKeycloakToken(['resource_access' => ['orders-api' => ['roles' => ['other']]]])
    ->getJson('/api/v1/orders')->assertUnauthorized();

// Вход пользователя с ролью в сессии, без Socialite
$this->actingAsKeycloakUser($user, 'admin')->get('/')->assertOk();
```

Трейт берёт claims токена из конфига, поэтому в `phpunit.xml` проекта должны быть заданы `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_API_AUDIENCE` и `KEYCLOAK_API_ROLE` (или `KEYCLOAK_API_CLIENTS`, если доступ по списку `azp`). Без `KEYCLOAK_API_AUDIENCE` guard отвечает 401 на любой токен, как и без `KEYCLOAK_API_ROLE` и `KEYCLOAK_API_CLIENTS` одновременно.

```xml
<env name="KEYCLOAK_BASE_URL" value="https://keycloak.test"/>
<env name="KEYCLOAK_REALM" value="corp"/>
<env name="KEYCLOAK_API_AUDIENCE" value="orders-api"/>
<env name="KEYCLOAK_API_ROLE" value="api"/>
```

- `withKeycloakToken()` генерирует ключ RSA и подставляет его публичную часть в guard вместо JWKS Keycloak. Guard не ходит ни в сеть, ни в кеш, поэтому `Http::fake` проекта ему не мешает, `Http::assertNothingSent()` проходит, а хранилище кеша в тестах может быть любым. Claims по умолчанию берутся из конфига: `iss`, `aud`, роль `KEYCLOAK_API_ROLE` в `resource_access` и `azp`, первый из `KEYCLOAK_API_CLIENTS` (без списка `azp` в токене нет). Значение `null` убирает claim.
- `keycloakToken($claims)` отдаёт сам токен, например для своих заголовков.
- Несколько вызовов `withKeycloakToken()` в одном тесте работают: перед каждым трейт сбрасывает запомненного guard-ом пользователя. Если токен из `keycloakToken()` отправляется своим заголовком, перед вторым запросом вызовите `app('auth')->forgetGuards()` сами.

## Разработка

```bash
composer test    # Larastan, Pint, покрытие типов, Pest
```

Демо-приложение (workbench) для ручной проверки входа:

```bash
cp workbench/.env.example workbench/.env   # KEYCLOAK_BYPASS=true: вход без Keycloak
composer serve                              # http://localhost:8000
```

Для входа через настоящий Keycloak заполните `KEYCLOAK_*` в `workbench/.env`, поставьте `KEYCLOAK_BYPASS=false` и разрешите в клиенте redirect URI `http://localhost:8000/auth/callback`. Роли демо задаются в `workbench/app/Providers/WorkbenchServiceProvider.php`.

Изменения описаны в [CHANGELOG](CHANGELOG.md).

Нашли уязвимость? Не пишите в открытый issue, сообщите приватно: [Report a vulnerability](https://github.com/marchenkovma/laravel-keycloak/security/advisories/new). Подробнее в [политике безопасности](.github/SECURITY.md).

## Лицензия

[MIT](LICENSE.md).
