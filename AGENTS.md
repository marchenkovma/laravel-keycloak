# Laravel Keycloak

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `marchenkovma/laravel-keycloak`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.

## Commits

Conventional Commits, as in employee-service, portguard and geospm:

- Subject: `type(scope): описание` in Russian, no trailing period. Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `ci`. Scope is the package area: `web`, `api`, `client`, `config`, `testing`, `release`.
- Body in Russian: why the change was made and what is not obvious from the diff. Wrap at about 72 characters.
- No `Co-Authored-By` trailer.

## Writing style

- Never use the em dash (—) or en dash (–) in code comments, docs, README, commit messages or test names. Rephrase with a colon, a comma, or a separate sentence. A plain hyphen is fine inside words and ranges.

## Documentation

- `README.md` is Russian, `README.en.md` is its English translation. Change both in the same commit, keep the sections in the same order, and keep the language switch line at the top of each.
