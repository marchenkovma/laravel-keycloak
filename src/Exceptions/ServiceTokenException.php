<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Exceptions;

use RuntimeException;

/**
 * Keycloak не выдал токен client credentials. В тексте клиент, адрес
 * и ответ Keycloak, но никогда не секрет.
 */
class ServiceTokenException extends RuntimeException {}
