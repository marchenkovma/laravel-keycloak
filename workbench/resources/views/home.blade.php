@use('Marchenkovma\LaravelKeycloak\Facades\Keycloak')
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel Keycloak: демо</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; max-width: 40rem; margin: 3rem auto; padding: 0 1rem; }
        dt { font-weight: 600; } dd { margin: 0 0 .75rem; }
        .notice { padding: .5rem .75rem; background: #fff6d5; border-radius: .25rem; }
    </style>
</head>
<body>
    <h1>Laravel Keycloak</h1>

    @if (session('auth_notice'))
        <p class="notice">auth_notice: {{ session('auth_notice') }}</p>
    @endif

    @auth
        <dl>
            <dt>Пользователь</dt>
            <dd>{{ auth()->user()->username }} ({{ auth()->user()->name }}, {{ auth()->user()->email }})</dd>
            <dt>Роль в сессии</dt>
            <dd>{{ Keycloak::role() }}</dd>
            <dt>id_token в сессии</dt>
            <dd>{{ Keycloak::idToken() !== null ? 'есть' : 'нет (bypass)' }}</dd>
        </dl>

        <p><a href="{{ route('admin') }}">Страница только для admin</a></p>

        <form method="post" action="{{ route('auth.logout') }}">
            @csrf
            <button type="submit">Выйти</button>
        </form>
    @else
        <p>Вы не вошли.</p>
        <p><a href="{{ route('auth.login') }}">Войти через Keycloak</a></p>
        <p>Bypass: {{ Keycloak::bypassEnabled() ? 'включён, вход без Keycloak' : 'выключен' }}</p>
    @endauth
</body>
</html>
