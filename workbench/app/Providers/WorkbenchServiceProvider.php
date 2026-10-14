<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Роли демо: admin по группе или роли клиента, user по группе.
        // Под свой realm поменяйте пути групп
        config(['keycloak.web.roles' => [
            'admin' => ['groups' => ['/Administrators'], 'client_roles' => ['admin']],
            'user' => ['groups' => ['/apps/demo/user'], 'client_roles' => ['user']],
        ]]);
    }
}
