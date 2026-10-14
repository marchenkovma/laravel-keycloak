<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Локальный пользователь для вошедшего через Keycloak. Проект подменяет
 * реализацию через $this->app->bind(ResolvesKeycloakUser::class, ...).
 */
interface ResolvesKeycloakUser
{
    /**
     * Найти или создать пользователя по данным userinfo Keycloak.
     */
    public function resolve(SocialiteUser $user): Authenticatable;

    /**
     * Фейковый пользователь для bypass-режима (локальная разработка).
     */
    public function bypass(): Authenticatable;
}
