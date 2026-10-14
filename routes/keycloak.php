<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marchenkovma\LaravelKeycloak\Http\Controllers\KeycloakController;

// OIDC-флоу вне auth: сюда попадают гости. Имена auth.*, как в проектах
Route::middleware(config('keycloak.web.middleware'))
    ->prefix(config('keycloak.web.prefix'))
    ->name('auth.')
    ->group(function (): void {
        Route::get('login', [KeycloakController::class, 'login'])->name('login');
        Route::get('callback', [KeycloakController::class, 'callback'])->name('callback');
        Route::post('logout', [KeycloakController::class, 'logout'])->name('logout');
    });
