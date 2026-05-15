<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\Internal\ClientCoreState;

/**
 * Mutable connection + option state shared by {@see RedisClient} instances using the same
 * {@code persistent_id} (mirrors {@see \PureCache\Memcached\Internal\MemcachedClientCore} for memcached).
 */
final class RedisClientState extends ClientCoreState
{
    /** @var array<int, NativeRedisClient> */
    public array $redisByServerIndex = [];

    private function __construct(?string $persistentId = null)
    {
        parent::__construct($persistentId);
    }

    public static function createFresh(?string $persistentId = null): self
    {
        return new self($persistentId);
    }
}
