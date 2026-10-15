<?php

declare(strict_types=1);

return [

    // Адрес Keycloak, по которому ходит приложение (может быть внутренним),
    // realm и клиент, от имени которого входят пользователи
    'base_url' => env('KEYCLOAK_BASE_URL'),
    'realm' => env('KEYCLOAK_REALM'),
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),

    // Вход пользователей
    'web' => [
        'routes' => true,             // false: проект регистрирует маршруты сам
        'prefix' => 'auth',           // auth/login, auth/callback, auth/logout
        'middleware' => ['web'],

        // Роли по приоритету, берётся первая совпавшая. groups: полные пути
        // групп (claim `groups`, mapper с Full group path), client_roles:
        // роли клиента (claim `roles`)
        'roles' => [
            // 'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
            // 'user' => ['groups' => ['/apps/my-app/user']],
        ],

        'redirects' => [
            'after_login' => 'home',  // имя маршрута, intended() поверх
            'after_logout' => 'home',
            'on_failure' => null,     // куда уводить при устаревшем state; null: на after_logout
        ],

        // Текст отказа (403) лежит в переводах: keycloak::messages.denied,
        // переопределяется в lang/vendor/keycloak/{locale}/messages.php

        // Локальная разработка без Keycloak: вход фейковым пользователем
        // с ролью bypass_role. В production не действует
        'bypass' => (bool) env('KEYCLOAK_BYPASS', false),
        'bypass_role' => env('KEYCLOAK_BYPASS_ROLE', 'admin'),
    ],

    // Проверка токенов сервисных клиентов в API (guard keycloak)
    'api' => [
        'issuer' => env('KEYCLOAK_ISSUER'),            // null: {base_url}/realms/{realm}
        'audience' => env('KEYCLOAK_API_AUDIENCE'),
        'clients' => env('KEYCLOAK_API_CLIENTS', ''),  // azp через запятую
        'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 3600),
        'leeway' => 30,                                // допуск exp/nbf, секунды
    ],

    // Токен client credentials для исходящих запросов (Keycloak::serviceToken).
    // Если пусто, берутся client_id и client_secret из корня
    'client_credentials' => [
        'client_id' => env('KEYCLOAK_SERVICE_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_SERVICE_CLIENT_SECRET'),
    ],

];
