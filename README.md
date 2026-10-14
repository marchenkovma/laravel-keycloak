<div align="center">
    <h1>Laravel Keycloak</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/marchenkovma/laravel-keycloak"><img src="https://img.shields.io/packagist/v/marchenkovma/laravel-keycloak.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/marchenkovma/laravel-keycloak"><img src="https://img.shields.io/packagist/php-v/marchenkovma/laravel-keycloak.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/marchenkovma/laravel-keycloak"><img src="https://badge.laravel.cloud/badge/marchenkovma/laravel-keycloak?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/marchenkovma/laravel-keycloak/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/marchenkovma/laravel-keycloak/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/marchenkovma/laravel-keycloak"><img src="https://img.shields.io/packagist/dt/marchenkovma/laravel-keycloak.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Keycloak for Laravel: user login via OIDC, API guard for service-account tokens, client credentials tokens.

## Installation

You can install the package via Composer:

```bash
composer require marchenkovma/laravel-keycloak
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-keycloak"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-keycloak-config"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Keycloak! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Maksim Marchenkov](https://github.com/marchenkovma)
- [All Contributors](../../contributors)

## License

Laravel Keycloak is open-sourced software licensed under the [MIT license](LICENSE.md).
