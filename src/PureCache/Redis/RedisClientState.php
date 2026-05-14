<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptions;
use PureCache\Internal\ServerSelector;

/**
 * Mutable connection + option state shared by {@see RedisClient} instances using the same
 * {@code persistent_id} (mirrors {@see \PureCache\Memcached\Internal\MemcachedClientCore} for memcached).
 */
final class RedisClientState extends ClientCoreState
{
    /** @var array<int, NativeRedisClient> */
    public array $redisByServerIndex = [];

    private function __construct()
    {
    }

    public static function createFresh(?string $persistentId = null): self
    {
        $c = new self();
        $c->persistentId = $persistentId;
        $c->selector = new ServerSelector();
        $c->options = ClientOptions::defaults();

        return $c;
    }
}
