<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;
use UnexpectedValueException;

/**
 * Пользователь в модели auth.providers.users.model, ключ: username
 * (preferred_username Keycloak). Поля пишутся через forceFill, поэтому
 * $fillable модели не важен.
 */
class EloquentUserResolver implements ResolvesKeycloakUser
{
    public function __construct(private readonly Config $config) {}

    public function resolve(SocialiteUser $user): Authenticatable
    {
        $username = $user->getNickname();

        // Пустой username объединил бы всех пользователей в одну запись:
        // firstOrNew(['username' => '']) находил бы её каждому
        if (! is_string($username) || $username === '') {
            throw new UnexpectedValueException('Keycloak: в userinfo нет preferred_username. Проверьте mapper username у клиента.');
        }

        $class = $this->config->get('auth.providers.users.model');

        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            throw new UnexpectedValueException('auth.providers.users.model must be an Eloquent model implementing Authenticatable.');
        }

        // Без firstOrNew: он заполняет новую модель через fill, а username
        // в $fillable проекта обычно нет. Полностью guarded модель или
        // Model::shouldBeStrict() бросили бы MassAssignmentException
        /** @var Model&Authenticatable $model */
        $model = $class::query()->where('username', $username)->first() ?? new $class;

        $model->forceFill([
            'username' => $username,
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'last_login_at' => now(),
        ])->save();

        return $model;
    }
}
