<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marchenkovma\LaravelKeycloak\Keycloak;
use Symfony\Component\HttpFoundation\Response;

/**
 * keycloak.role пускает с любой ролью приложения, keycloak.role:admin,user
 * с одной из перечисленных. Без роли 403 с keycloak::messages.denied.
 */
class EnsureKeycloakRole
{
    public function __construct(private readonly Keycloak $keycloak) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = $roles === []
            ? $this->keycloak->role() !== null
            : $this->keycloak->hasRole(...$roles);

        abort_unless($allowed, 403, __('keycloak::messages.denied'));

        return $next($request);
    }
}
