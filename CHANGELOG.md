# Release Notes

## [Unreleased](https://github.com/marchenkovma/laravel-keycloak/compare/v0.1.0...HEAD)

## [v0.1.0](https://github.com/marchenkovma/laravel-keycloak/releases/tag/v0.1.0) - 2026-10-14

Первая версия: общий код входа через Keycloak для employee-service и portguard.

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
