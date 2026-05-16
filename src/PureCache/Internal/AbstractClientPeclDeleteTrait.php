<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL delete/deleteMulti surface shared by {@see \PureCache\AbstractCacheClient}.
 *
 * @phpstan-require-extends \PureCache\AbstractCacheClient
 */
trait AbstractClientPeclDeleteTrait
{
    abstract protected function doDelete(string $key, ?string $serverKey, int $time): bool;

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    abstract protected function runDeleteMulti(array $keys, ?string $serverKey, int $time): array;

    #[\Override]
    public function delete(string $key, int $time = 0): bool
    {
        return $this->doDelete($key, null, $time);
    }

    #[\Override]
    public function deleteByKey(string $server_key, string $key, int $time = 0): bool
    {
        return $this->doDelete($key, $server_key, $time);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    #[\Override]
    public function deleteMulti(array $keys, int $time = 0): array
    {
        return $this->runDeleteMulti($keys, null, $time);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    #[\Override]
    public function deleteMultiByKey(string $server_key, array $keys, int $time = 0): array
    {
        return $this->runDeleteMulti($keys, $server_key, $time);
    }
}
