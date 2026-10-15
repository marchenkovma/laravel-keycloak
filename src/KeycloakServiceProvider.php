<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use Marchenkovma\LaravelKeycloak\Api\JwksSigningKeys;
use Marchenkovma\LaravelKeycloak\Api\SigningKeys;
use Marchenkovma\LaravelKeycloak\Api\TokenVerifier;
use Marchenkovma\LaravelKeycloak\Auth\EloquentUserResolver;
use Marchenkovma\LaravelKeycloak\Contracts\ResolvesKeycloakUser;
use Marchenkovma\LaravelKeycloak\Http\Middleware\EnsureKeycloakRole;
use SocialiteProviders\Keycloak\Provider as KeycloakProvider;
use SocialiteProviders\Manager\Config as ProviderConfig;

class KeycloakServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/keycloak.php', 'keycloak');

        $this->app->singleton(Keycloak::class);

        // Singleton: разобранные ключи переживают запрос под Octane и в очередях.
        // Трейт InteractsWithKeycloak подменяет его на FakeSigningKeys
        $this->app->singleton(SigningKeys::class, JwksSigningKeys::class);

        // Проект подменяет своим bind-ом в AppServiceProvider
        $this->app->bindIf(ResolvesKeycloakUser::class, EloquentUserResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSocialiteDriver();
        $this->registerApiGuard();

        // false: проект регистрирует маршруты сам (KeycloakController)
        if ($this->app->make(Config::class)->get('keycloak.web.routes') === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/keycloak.php');
        }

        $this->app->make(Router::class)->aliasMiddleware('keycloak.role', EnsureKeycloakRole::class);

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'keycloak');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/keycloak.php' => config_path('keycloak.php'),
        ], ['keycloak', 'keycloak-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/keycloak'),
        ], ['keycloak', 'keycloak-lang']);
    }

    /**
     * Драйвер guard-а keycloak: проект подключает его в config/auth.php,
     * 'keycloak' => ['driver' => 'keycloak']. На чужой или битый токен
     * пользователь null: auth:keycloak,sanctum доходит до Sanctum.
     */
    private function registerApiGuard(): void
    {
        $this->callAfterResolving('auth', function (AuthManager $auth): void {
            $auth->viaRequest('keycloak', function (Request $request): ?Authenticatable {
                $token = $request->bearerToken();

                if ($token === null || $token === '') {
                    return null;
                }

                $claims = $this->app->make(TokenVerifier::class)->verify($token);

                return $claims === null ? null : $this->app->make(Keycloak::class)->apiClient($claims);
            });
        });
    }

    /**
     * Драйвер keycloak для Socialite. Настройки читаются из config('keycloak')
     * в момент создания драйвера, services.keycloak проекту не нужен.
     */
    private function registerSocialiteDriver(): void
    {
        $this->callAfterResolving(SocialiteFactory::class, function (SocialiteManager $socialite): void {
            // Laravel привязывает замыкание к менеджеру Socialite, поэтому
            // контейнер берётся из аргумента, а не из $this->app
            $socialite->extend('keycloak', function (Container $app) use ($socialite): KeycloakProvider {
                $config = $app->make(Config::class);

                // redirect_uri из маршрута auth.callback: при web.routes = false
                // проект регистрирует его сам и может положить по другому пути.
                // Без именованного маршрута: {prefix}/callback
                $redirect = $app->make('router')->has('auth.callback')
                    ? route('auth.callback')
                    : url('/'.trim((string) $config->get('keycloak.web.prefix'), '/').'/callback');

                $settings = [
                    'client_id' => $config->get('keycloak.client_id'),
                    'client_secret' => $config->get('keycloak.client_secret'),
                    'redirect' => $redirect,
                ];

                /** @var KeycloakProvider $provider */
                $provider = $socialite->buildProvider(KeycloakProvider::class, $settings);

                // Ключи base_url и realms (с s) диктует socialiteproviders/keycloak
                return $provider->setConfig(new ProviderConfig(
                    $settings['client_id'],
                    $settings['client_secret'],
                    $settings['redirect'],
                    [
                        'base_url' => $config->get('keycloak.base_url'),
                        'realms' => $config->get('keycloak.realm'),
                    ],
                ));
            });
        });
    }
}
