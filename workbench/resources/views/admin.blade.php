<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>Laravel Keycloak: admin</title>
</head>
<body style="font: 16px/1.5 system-ui, sans-serif; max-width: 40rem; margin: 3rem auto;">
    <h1>Только для admin</h1>
    <p>Middleware keycloak.role:admin пропустил.</p>
    <p><a href="{{ route('home') }}">На главную</a></p>
</body>
</html>
