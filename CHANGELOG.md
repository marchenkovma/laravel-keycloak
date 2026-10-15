# Release Notes

## [Unreleased](https://github.com/marchenkovma/laravel-keycloak/compare/v0.2.0...HEAD)

## [v0.2.0](https://github.com/marchenkovma/laravel-keycloak/compare/v0.1.0...v0.2.0) - 2026-10-15

Требования прежние: PHP 8.4+ с расширением openssl, Laravel 13.

### Несовместимые изменения

- `Keycloak::roleFor()` принимает claims userinfo одним массивом: `Keycloak::roleFor(['groups' => [...], 'roles' => [...]])` вместо двух отдельных массивов.
- В контракте `ResolvesKeycloakUser` остался один метод `resolve()`. В bypass-режиме в него приходит фейковый пользователь: username `bypass`, имя из `keycloak::messages.bypass_name`, почта `bypass@localhost`. Метод `bypass()` из своей реализации можно удалить.
- У `TokenVerifier` публичными остались `verify()` и `acceptedClaims()`. Методы `issuer()`, `clients()`, `jwksUrl()` и `cacheJwks()` убраны.

### Вход пользователей

- `Keycloak::idToken()`: id_token входа из сессии. Запись сессии ведёт только класс `Keycloak`, а `actingAsKeycloakUser()` пишет её тем же методом, что и настоящий вход.
- Пустой id_token в сессии считается отсутствующим: выход идёт сразу на `redirects.after_logout`, без end_session.

### API

- Ключи подписи берутся через интерфейс `SigningKeys`. В приложении это `JwksSigningKeys`: JWKS realm-а, кеш и ротация ключей. Разобранные ключи переиспользуются в памяти процесса, пока в кеше тот же JWKS, поэтому под Octane и в очередях JWKS не разбирается на каждый запрос.
- В логе отказа причина `unknown kid` заменена на `no signing key for kid`.

### Тесты

- `withKeycloakToken()` и `keycloakToken()` больше не пишут в кеш: тестовый ключ подставляется через `FakeSigningKeys`. `CACHE_STORE=array` в тестах проекта больше не нужен.

## [v0.1.0](https://github.com/marchenkovma/laravel-keycloak/releases/tag/v0.1.0) - 2026-10-14

Требования: PHP 8.4+ с расширением openssl, Laravel 13.

### Вход пользователей

- Маршруты `auth/login`, `auth/callback`, `auth/logout`: Authorization Code + PKCE через Socialite, драйвер `keycloak` регистрирует пакет, `services.keycloak` не нужен.
- Роль приложения по группам и ролям клиента (`keycloak.web.roles`), хранится в сессии. `Keycloak::role()`, `Keycloak::hasRole()`, middleware `keycloak.role`.
- Устаревший state или `error` от Keycloak ведут на `redirects.on_failure` с `auth_notice` (`session_expired`, `login_failed`), выход на end_session с `id_token_hint`, для Inertia 409 с `X-Inertia-Location`.
- Bypass-вход для локальной разработки, в production не действует.
- Свой пользователь через `ResolvesKeycloakUser`, тексты в переводах `keycloak::messages` (ru, en).

### API

- Guard `keycloak`: проверка access-токенов сервисных клиентов по JWKS realm-а (RS256, `iss`, `aud`, `azp`, обязательный `exp`). На чужой токен уступает следующему guard-у, поэтому работает `auth:keycloak,sanctum`.
- `Keycloak::serviceToken()`: токен client credentials для исходящих запросов с кешем до `expires_in` минус 30 с.

### Тесты

- Трейт `InteractsWithKeycloak`: `withKeycloakToken()`, `keycloakToken()`, `actingAsKeycloakUser()`. Тестовый JWKS кладётся в кеш, в сеть guard не ходит.
