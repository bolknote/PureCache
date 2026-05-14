<?php

declare(strict_types=1);

namespace PureCache\Redis;

/**
 * Control-flow exception for Memcached-compatible store outcomes on the Redis backend.
 * Caught locally and mapped to {@see MemcachedConstants} result codes — not part of the public API.
 */
final class RedisClientStoreException extends \RuntimeException
{
    public function __construct(
        public readonly int $outcome,
        string $message = '',
    ) {
        parent::__construct('' !== $message ? $message : 'RedisClientStoreException');
    }
}
