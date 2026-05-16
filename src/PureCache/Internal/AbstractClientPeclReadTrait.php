<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL get / getMulti / delayed-fetch surface for {@see \PureCache\AbstractCacheClient}.
 *
 * @phpstan-require-extends \PureCache\AbstractCacheClient
 */
trait AbstractClientPeclReadTrait
{
    abstract protected function prefixedKey(string $key): string;

    abstract protected function coordinators(): ClientCoordinatorRegistry;

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    abstract protected function getMultiCommon(array $keys, ?string $serverKey, int $getFlags): array|false;

    #[\Override]
    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->coordinators()->read()->get($key, $this->prefixedKey($key), null, $cache_cb, $get_flags);
    }

    #[\Override]
    public function getByKey(string $server_key, string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->coordinators()->read()->get($key, $this->prefixedKey($key), $server_key, $cache_cb, $get_flags);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getMulti(array $keys, int $get_flags = 0): array|false
    {
        return $this->getMultiCommon($keys, null, $get_flags);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getMultiByKey(string $server_key, array $keys, int $get_flags = 0): array|false
    {
        return $this->getMultiCommon($keys, $server_key, $get_flags);
    }

    /**
     * @param array<mixed> $keys
     */
    #[\Override]
    public function getDelayed(array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        return $this->coordinators()->delayedFetch()->enqueueDelayed(null, $keys, $with_cas, $value_cb);
    }

    /**
     * @param array<mixed> $keys
     */
    #[\Override]
    public function getDelayedByKey(string $server_key, array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        return $this->coordinators()->delayedFetch()->enqueueDelayed($server_key, $keys, $with_cas, $value_cb);
    }

    /**
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function fetch(): array|false
    {
        return $this->coordinators()->delayedFetch()->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    #[\Override]
    public function fetchAll(): array|false
    {
        return $this->coordinators()->delayedFetch()->fetchAll();
    }
}
