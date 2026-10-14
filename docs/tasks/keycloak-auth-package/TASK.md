# Задача: пакет `marchenkovma/laravel-keycloak` — вход пользователей и API через Keycloak

_Дата: 2026-10-13. Потребители: employee-service и portguard (`~/projects/employee-service`, `~/projects/portguard`)._

## Зачем

В обоих проектах вход пользователей через Keycloak написан дважды, почти построчно одинаково (`app/Http/Controllers/Auth/OidcController.php`). Теперь обоим нужна ещё и проверка токенов сервисных клиентов Keycloak в API (client credentials):

- employee-service должен пускать Portguard в `/api/v1` по токену Keycloak. Это задача `~/projects/employee-service/docs/tasks/keycloak-service-accounts/TASK.md`, требования к проверке токена взяты оттуда.
- В portguard Sanctum убран 24.09, в `routes/api.php` записано «машины входят сервисными учётками Keycloak», но guard-а нет.
- Portguard в срезе 6б будет сам ходить в employee-service, значит ему нужно получать токен client credentials.

Вместо третьей копии делаем один пакет, который подключают оба проекта.

## Границы

- **Sanctum пакет не знает.** Ни зависимости в `composer.json`, ни кода про него. Совмещение с Sanctum — дело проекта: `auth:keycloak,sanctum` в employee-service, `auth:keycloak` в portguard.
- Отсюда главное правило guard-а: **на чужой или битый токен он возвращает null, а не бросает исключение.** Тогда `auth:keycloak,sanctum` доходит до Sanctum, а одиночный `auth:keycloak` даёт обычный 401.
- Inertia пакет не требует. Где нужен `Inertia::location`, пакет сам смотрит на заголовок `X-Inertia` и отвечает `409` с заголовком `X-Inertia-Location`.
- Подключение пакета в проекты в эту задачу не входит, см. «Не входит».

## Основа

Скелет: официальный **`laravel/package-skeleton`** (https://github.com/laravel/package-skeleton): Testbench, Pest, Larastan, Pint, workbench. Склонировать в корень этой папки, не затирая `docs/`, и настроить скриптом скелета:

```bash
composer install --no-scripts
php configure.php
```

После настройки проверить `composer test`. Неинтерактивный режим и флаги описаны в README скелета: `php configure.php --no-interaction --package-description=... --config --routes`. Под агентом скрипт сам уходит в неинтерактивный режим, поэтому метаданные передать явно.

- Пакет: `marchenkovma/laravel-keycloak`, автор Maksim Marchenkov.
- Namespace: `Marchenkovma\LaravelKeycloak\`.
- Провайдер: `KeycloakServiceProvider`, автоподключение через `extra.laravel.providers`.
- Нужные возможности скелета:
  - config;
  - routes (маршруты входа);
  - facade, если скрипт предлагает (`Keycloak::...`).
- Не нужны: миграции, views, assets, команды.
- Репозиторий на GitHub: `github.com/marchenkovma/laravel-keycloak`. Скрипт предлагает создать его через `gh`, но `gh` в WSL не установлен, так что репо создаёт пользователь.

`~/projects/my-package` — старая проба того же скелета с данными-заглушками. Не копировать его, брать свежий клон.

Ограничения версий: PHP `^8.3`, `illuminate/*` `^12.0||^13.0` (проекты на Laravel 13). Зависимости:

- `laravel/socialite` `^5.29`;
- `socialiteproviders/keycloak` `^5.3`;
- `firebase/php-jwt` `^7.1`.

Документация Laravel по пакетам: https://laravel.com/docs/packages

## Что сделать

### 1. Конфиг `config/keycloak.php`

Публикуется через `publishes` и подмешивается через `mergeConfigFrom`. Ключ в проекте — `keycloak`. Значения берутся из env, комментарии на русском, в стиле проектов.

```php
return [
    'base_url' => env('KEYCLOAK_BASE_URL'),
    'realm' => env('KEYCLOAK_REALM'),
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),

    // Вход пользователей
    'web' => [
        'routes' => true,             // false: проект регистрирует маршруты сам
        'prefix' => 'auth',           // auth/login, auth/callback, auth/logout
        'middleware' => ['web'],
        // Роли по приоритету: первая совпавшая. groups — полные пути
        // (claim `groups`), client_roles — роли клиента (claim `roles`)
        'roles' => [
            // 'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
            // 'user' => ['groups' => ['/apps/employees/user']],
        ],
        'redirects' => [
            'after_login' => 'home',       // имя маршрута, intended() поверх
            'after_logout' => 'home',
            'on_failure' => null,          // куда уводить при устаревшем state; null — на after_logout
        ],
        'denied_message' => 'Нет доступа: обратитесь к администратору за ролью в приложении.',
        'bypass' => env('KEYCLOAK_BYPASS', false),
        'bypass_role' => env('KEYCLOAK_BYPASS_ROLE', 'admin'),
    ],

    // Проверка токенов сервисных клиентов в API
    'api' => [
        'issuer' => env('KEYCLOAK_ISSUER'),          // null — {base_url}/realms/{realm}
        'audience' => env('KEYCLOAK_API_AUDIENCE'),
        'clients' => env('KEYCLOAK_API_CLIENTS', ''), // через запятую, разбирать в коде
        'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 3600),
        'leeway' => 30,
    ],
];
```

Socialite-провайдер ждёт ключи `base_url`, `realms`, `client_id`, `client_secret` и `redirect` в `config('services.keycloak')`. Пакет сам складывает их туда из `config('keycloak')` при регистрации драйвера, чтобы проекту не держать два места. Регистрация драйвера: `Event::listen(SocialiteWasCalled ...)` — сейчас это делается в `AppServiceProvider` обоих проектов.

Набор ключей выше — предложение. Если по ходу понадобится иначе, менять смело, но так, чтобы оба проекта ложились без кода-обёртки.

### 2. Вход пользователей

Взять за основу `OidcController` из **portguard**: он новее и обрабатывает устаревший state. Сверить с employee-service.

- `login`: Socialite `keycloak` с `enablePKCE()`. Если `bypass` включён и окружение не production, войти фейковым пользователем с ролью `bypass_role`.
- `callback`:
  - `InvalidStateException` → редирект на `on_failure` с flash `auth_notice=session_expired`;
  - роль по `roles` из конфига; нет роли → 403 с `denied_message`;
  - найти или создать пользователя (см. ниже), `Auth::login`, `session()->regenerate()`;
  - в сессию `keycloak = ['role' => ..., 'id_token' => ...]`, затем `redirect()->intended(after_login)`.
- `logout`:
  - выйти, `invalidate`, `regenerateToken`, flash `auth_notice=signed_out`;
  - без `id_token` (bypass) — сразу на `after_logout`;
  - иначе на end_session Keycloak с `id_token_hint`. Для Inertia-запроса — 409 с `X-Inertia-Location`, иначе обычный redirect.
- Пользователь: интерфейс `ResolvesKeycloakUser` (`resolve(SocialiteUser): Authenticatable` и `bypass(): Authenticatable`). Реализация по умолчанию повторяет нынешнюю: `updateOrCreate` по `username` (nickname) с полями `name`, `email`, `last_login_at` в модели `config('auth.providers.users.model')`. Проект может подменить реализацию через bind.
- Роль в сессии: хелпер `Keycloak::role(): ?string` и `Keycloak::hasRole(string ...$roles): bool`. Middleware-алиас `keycloak.role:admin,user` отдаёт 403 с `denied_message`. Gate-ы проект определяет сам через хелпер.

Страницу Welcome, `redirectGuestsTo` и обработку `AuthenticationException` для Inertia пакет не делает, они остаются в проектах. В README описать, что там прописать: примеры есть в `bootstrap/app.php` обоих проектов.

### 3. Guard `keycloak` для API

Драйвер guard-а регистрирует пакет. Проект добавляет в `config/auth.php`:

```php
'keycloak' => ['driver' => 'keycloak'],
```

Проверка `Authorization: Bearer <jwt>`:

- подпись по JWKS realm-а `{base_url}/realms/{realm}/protocol/openid-connect/certs`:
  - качать через `Http`, чтобы работал `Http::fake`;
  - в кеше хранить сырой JWKS: объекты `Key` с ключами OpenSSL не сериализуются;
  - брать только `kty=RSA` и `use=sig` (Keycloak отдаёт рядом ключ шифрования `RSA-OAEP`);
- неизвестный `kid` — это ротация: перечитать JWKS, но не чаще раза в минуту (`Cache::add`-замок);
- алгоритм только RS256: при разборе JWKS жёстко ставить `alg=RS256`, тогда php-jwt отвергнет `none` и HS*;
- `iss` равен `api.issuer`, а без него — `{base_url}/realms/{realm}`. `iss` — публичный адрес Keycloak, JWKS берётся по `base_url`, который может быть внутренним;
- `aud` (строка или массив) содержит `api.audience`;
- `exp` обязателен: php-jwt проверяет срок только если поле есть;
- `exp`/`nbf` с допуском `leeway`;
- `azp` входит в `api.clients`;
- не JWT (нет трёх частей или `kid`) — сразу null, в Keycloak не ходить: так токены Sanctum вида `1|abc` проходят мимо без сетевых запросов;
- JWKS не скачались — лог warning без токена, null, то есть 401, а не 500;
- отказ по подписи или claims — лог info с `kid`, `iss`, `azp` и причиной, без самого токена.

Пользователь guard-а — лёгкий класс пакета, например `KeycloakClient implements Authenticatable`:

- `getAuthIdentifier()` = `azp`;
- доступ к claims: `$client->claims`, `$client->hasRole($resource, $role)` по `resource_access`, `$client->hasScope($scope)`.

Можно дать проекту подменить его на свой класс через конфиг или замыкание. Например, employee-service может захотеть несохранённый `ApiClient(['name' => $azp])`.

Черновик проверки, сверенный с API php-jwt 7.1: `token-verifier-draft.md` рядом с этим файлом.

### 4. Токен client credentials для исходящих запросов

`Keycloak::serviceToken(): string`:

- POST `grant_type=client_credentials` на token-эндпоинт realm-а, с `client_id`/`client_secret` из конфига;
- кешировать до `expires_in` минус 30 с.

Отдельные учётные данные под другой клиент — опциональными аргументами или отдельной секцией конфига, на выбор реализующего. Нужен portguard-у для запросов в employee-service (срез 6б). Ошибка Keycloak — исключение пакета с понятным текстом, без секрета.

### 5. Помощники для тестов проектов

Трейт `InteractsWithKeycloak` (идея взята из `robsontenorio/laravel-keycloak-guard`, `ActingAsKeycloakUser`):

- `withKeycloakToken(array $claims = [])`:
  - генерирует RSA-пару;
  - подменяет JWKS через `Http::fake`;
  - ставит заголовок `Authorization`;
  - разумные claims по умолчанию берёт из конфига: `iss`, `aud`, первый из `clients`;
- `actingAsKeycloakUser(Authenticatable $user, string $role)`: вход пользователя с ролью в сессии, без Socialite.

## Засады

- **Кеш RequestGuard.** Guard через `Auth::viaRequest` запоминает пользователя. В тестах с двумя запросами подряд вызывать `app('auth')->forgetGuards()`. Иначе второй запрос пройдёт на пользователе из первого, и тест ротации будет зелёным впустую.
- **`JWT::$leeway` — глобальная статика php-jwt.** Ставить перед каждым `decode`.
- **Socialite и конфиг.** Ключ `realms` (с s) диктует `socialiteproviders/keycloak`. `getLogoutUrl` провайдера уже умеет `id_token_hint`.
- **Внутренний корневой сертификат.** JWKS и token-эндпоинт ходят по HTTPS на Keycloak с корпоративным CA. В контейнерах проектов он уже доверенный (`docker/php/Dockerfile.prod`), пакету делать ничего не нужно, но в README упомянуть.

## Тесты (Pest + Testbench)

RSA-пара генерируется в тесте, JWKS подменяется через `Http::fake`, Socialite мокается так, как это сделано в `tests/Feature/OidcAuthTest.php` (employee-service) и `tests/Feature/OidcFlowTest.php` (portguard).

API-guard:

- токен разрешённого клиента с верными `iss`/`aud`/`exp` проходит, пользователь — `KeycloakClient` с нужным `azp`;
- null (401) при:
  - чужой подписи;
  - просроченном токене;
  - чужом `iss`;
  - отсутствии нужного `aud`;
  - `azp` не из списка;
  - `alg: none`;
  - HS256, подписанном публичным ключом;
  - отсутствии `exp`;
- JWKS недоступен — 401, не 500;
- новый `kid` после ротации: JWKS перечитан, запрос прошёл; повторный мусорный `kid` в течение минуты в Keycloak не ходит;
- не-JWT токен — null без HTTP-запросов;
- **guard уступает очередь:** на маршруте `auth:keycloak,other`, где `other` — фиктивный guard из теста, токен для `other` проходит.

Вход пользователей: перенести поведение из тестов обоих проектов.

- employee-service, `OidcAuthTest`:
  - PKCE-редирект;
  - callback создаёт или обновляет пользователя и кладёт роль;
  - без групп — 403;
  - logout на end_session с `id_token_hint`, Inertia-logout — 409;
  - logout без `id_token`;
  - bypass, и bypass игнорируется в production.
- portguard, `OidcFlowTest` и `KeycloakAccessTest`:
  - роль по группе, роль по роли клиента;
  - роль, которой нет в конфиге, входа не даёт;
  - устаревший state ведёт на `on_failure` с `auth_notice`, а не 500;
  - logout на `after_logout` с `signed_out`.
- Middleware `keycloak.role`: пускает нужную роль, остальным 403.

`serviceToken()`:

- токен кешируется, повторный вызов не ходит в Keycloak;
- после истечения запрашивается заново;
- ошибка Keycloak — исключение без секрета в тексте.

## Готово, когда

- [ ] `composer test` в пакете зелёный: Larastan, Pint, Pest.
- [ ] README: установка, env-ключи, `config/auth.php`, примеры `auth:keycloak` и `auth:keycloak,sanctum`, что прописать в `bootstrap/app.php` для Inertia, тестовый трейт, настройка клиента в Keycloak (audience mapper, service account). Раздел про Keycloak взять из TASK.md employee-service.
- [ ] Тег `v0.1.0`.

## Не входит

- **Подключение пакета в employee-service и portguard.** Это отдельные задачи в проектах: сначала employee-service через path-репозиторий с `symlink: true`, затем portguard. Их существующие тесты `OidcAuthTest` и `OidcFlowTest` должны остаться зелёными без правок по смыслу.
- Права по ролям и scope на отдельных маршрутах API (методы `hasRole`/`hasScope` есть, middleware под них — потом).
- Refresh-токены и хранение access-токена пользователя: токены в браузер не попадают, пользователям хватает сессии.

## Открытые вопросы (уточнить у пользователя в начале сессии)

- Репозиторий публичный или приватный? Рекомендация — публичный: секретов в коде нет, а для приватного `composer install` в `Dockerfile.prod` проектов нужен токен GitHub через `COMPOSER_AUTH`.
