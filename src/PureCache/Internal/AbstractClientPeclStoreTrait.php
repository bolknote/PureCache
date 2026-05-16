<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL store/touch/cas surface shared by {@see \PureCache\AbstractCacheClient}.
 *
 * @phpstan-require-extends \PureCache\AbstractCacheClient
 */
trait AbstractClientPeclStoreTrait
{
    abstract protected function doStore(string $key, mixed $value, int $expiration, StoreMode $mode, ?string $serverKey, ?string $casToken): bool;

    /**
     * @param array<mixed> $items
     */
    abstract protected function doStoreMulti(array $items, int $expiration, ?string $serverKey): bool;

    abstract protected function doTouch(string $key, int $expiration, ?string $serverKey): bool;

    /**
     * @param array<mixed> $items
     */
    abstract protected function storeMultiValidate(?string $serverKey, array $items): bool;

    #[\Override]
    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, null, null);
    }

    #[\Override]
    public function setByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, $server_key, null);
    }

    #[\Override]
    public function touch(string $key, int $expiration = 0): bool
    {
        return $this->doTouch($key, $expiration, null);
    }

    #[\Override]
    public function touchByKey(string $server_key, string $key, int $expiration = 0): bool
    {
        return $this->doTouch($key, $expiration, $server_key);
    }

    /**
     * @param array<mixed> $items
     */
    #[\Override]
    public function setMulti(array $items, int $expiration = 0): bool
    {
        if (!$this->storeMultiValidate(null, $items)) {
            return false;
        }

        return $this->doStoreMulti($items, $expiration, null);
    }

    /**
     * @param array<mixed> $items
     */
    #[\Override]
    public function setMultiByKey(string $server_key, array $items, int $expiration = 0): bool
    {
        if (!$this->storeMultiValidate($server_key, $items)) {
            return false;
        }

        return $this->doStoreMulti($items, $expiration, $server_key);
    }

    #[\Override]
    public function add(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Add, null, null);
    }

    #[\Override]
    public function addByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Add, $server_key, null);
    }

    #[\Override]
    public function replace(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Replace, null, null);
    }

    #[\Override]
    public function replaceByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Replace, $server_key, null);
    }

    #[\Override]
    public function append(string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Append, null, null);
    }

    #[\Override]
    public function appendByKey(string $server_key, string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Append, $server_key, null);
    }

    #[\Override]
    public function prepend(string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Prepend, null, null);
    }

    #[\Override]
    public function prependByKey(string $server_key, string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Prepend, $server_key, null);
    }

    #[\Override]
    public function cas(string|int|float $cas_token, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, null, (string) $cas_token);
    }

    #[\Override]
    public function casByKey(string|int|float $cas_token, string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, $server_key, (string) $cas_token);
    }
}
