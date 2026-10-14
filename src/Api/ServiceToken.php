<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Api;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Marchenkovma\LaravelKeycloak\Exceptions\ServiceTokenException;
use Marchenkovma\LaravelKeycloak\Keycloak;
use Throwable;

/**
 * Access-токен client credentials для исходящих запросов в другие сервисы.
 * Кешируется до expires_in минус запас: токен не истечёт по дороге.
 */
class ServiceToken
{
    // Запас до конца жизни токена, секунды
    private const int EXPIRY_MARGIN = 30;

    public function __construct(
        private readonly Keycloak $keycloak,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Http $http,
    ) {}

    /**
     * Учётные данные парой из одного источника: аргументы, иначе
     * keycloak.client_credentials, иначе client_id и client_secret из корня
     * конфига. Половина пары не дополняется из следующего источника: id
     * одного клиента ушёл бы с секретом другого.
     *
     * @throws ServiceTokenException
     */
    public function get(?string $clientId = null, ?string $clientSecret = null): string
    {
        [$clientId, $clientSecret] = match (true) {
            $clientId !== null || $clientSecret !== null => [$clientId, $clientSecret],
            $this->string('client_credentials.client_id') !== null || $this->string('client_credentials.client_secret') !== null => [
                $this->string('client_credentials.client_id'),
                $this->string('client_credentials.client_secret'),
            ],
            default => [$this->string('client_id'), $this->string('client_secret')],
        };

        if ($clientId === null || $clientSecret === null) {
            throw new ServiceTokenException('Keycloak: для client credentials нужны client_id и client_secret вместе, из одного источника.');
        }

        $cacheKey = 'keycloak:service-token:'.hash('sha256', $this->tokenUrl().'|'.$clientId);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->request($clientId, $clientSecret);
        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($token) || $token === '') {
            throw new ServiceTokenException("Keycloak: в ответе на client credentials для клиента {$clientId} нет access_token.");
        }

        $ttl = (is_numeric($expiresIn) ? (int) $expiresIn : 0) - self::EXPIRY_MARGIN;

        // Короче запаса: не кешируем, иначе отдали бы почти истёкший токен
        if ($ttl > 0) {
            $this->cache->put($cacheKey, $token, $ttl);
        }

        return $token;
    }

    public function tokenUrl(): string
    {
        return $this->keycloak->realmUrl().'/protocol/openid-connect/token';
    }

    private function request(string $clientId, string $clientSecret): Response
    {
        try {
            $response = $this->http->asForm()->timeout(10)->post($this->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (Throwable $e) {
            // Текст исключения HTTP-клиента не пересказываем: в нём бывает тело запроса
            throw new ServiceTokenException("Keycloak: не удалось запросить токен client credentials для клиента {$clientId} по адресу {$this->tokenUrl()}: ".$e::class.'.', previous: $e);
        }

        if ($response->failed()) {
            // error и error_description из ответа Keycloak, например invalid_client
            $error = $response->json('error');
            $description = $response->json('error_description');

            throw new ServiceTokenException(sprintf(
                'Keycloak отказал в токене client credentials для клиента %s: HTTP %d%s%s.',
                $clientId,
                $response->status(),
                is_string($error) ? ", {$error}" : '',
                is_string($description) ? " ({$description})" : '',
            ));
        }

        return $response;
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get("keycloak.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
