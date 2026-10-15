<?php

declare(strict_types=1);

namespace Marchenkovma\LaravelKeycloak\Testing;

use Firebase\JWT\Key;
use Marchenkovma\LaravelKeycloak\Api\SigningKeys;

/**
 * Ключи подписи для тестов проектов: трейт InteractsWithKeycloak кладёт сюда
 * публичную часть тестового ключа. Guard не ходит ни в сеть, ни в кеш.
 */
class FakeSigningKeys implements SigningKeys
{
    /**
     * @param  array<string, Key>  $keys  ключи по kid
     */
    public function __construct(private readonly array $keys) {}

    public function find(string $kid): ?Key
    {
        return $this->keys[$kid] ?? null;
    }
}
