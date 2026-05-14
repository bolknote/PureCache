<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PureCache\CacheClient;
use PureCache\MemcachedConstants;

/**
 * Test-only {@see CacheClient} that records every call the session handler
 * makes against it. Implementing the interface directly (rather than mocking
 * the final {@see \PureCache\Memcached\MemcachedClient}) lets unit tests
 * exercise lock-retry timing, write fail-over, and destroy ordering without
 * a real memcached server.
 *
 * Configurable streams emulate transient backend errors:
 *  - {@see $addReturnStream} / {@see $addResultCodeStream} let a lock fight
 *    return {@code RES_NOTSTORED}/{@code RES_DATA_EXISTS} for the first N
 *    attempts before finally succeeding.
 *  - {@see $setReturnStream} drives the retry count of the write path.
 *  - {@see $getResultCodeStream} reproduces {@code RES_NOTFOUND} so the
 *    handler returns an empty string the same way PECL does.
 */
final class SessionSpyCacheClient implements CacheClient
{
    /** @var list<array{method:string,key?:string,value?:mixed,expiration?:int,option?:int}> */
    public array $calls = [];

    public mixed $getPayload = null;

    /** @var list<int> consumed once per get() */
    public array $getResultCodeStream = [];

    /** @var list<bool> consumed once per add() */
    public array $addReturnStream = [];

    /** @var list<int> consumed once per add() */
    public array $addResultCodeStream = [];

    /** @var list<bool> consumed once per set() */
    public array $setReturnStream = [];

    /** @var list<int> consumed once per set() */
    public array $setResultCodeStream = [];

    public bool $deleteReturn = true;

    public bool $touchReturn = true;

    private int $lastResultCode = MemcachedConstants::RES_SUCCESS;

    #[\Override]
    public function getResultCode(): int
    {
        return $this->lastResultCode;
    }

    #[\Override]
    public function getResultMessage(): string
    {
        return MemcachedConstants::RES_SUCCESS === $this->lastResultCode ? 'SUCCESS' : 'mock failure';
    }

    #[\Override]
    public function addServer(string $host, int $port, int $weight = 0): bool
    {
        $this->calls[] = ['method' => 'addServer', 'key' => $host.':'.$port];

        return true;
    }

    #[\Override]
    public function addServers(array $servers): bool
    {
        return true;
    }

    #[\Override]
    public function getServerList(): array
    {
        return [];
    }

    #[\Override]
    public function getServerByKey(string $server_key): array|false
    {
        return false;
    }

    #[\Override]
    public function resetServerList(): bool
    {
        return true;
    }

    #[\Override]
    public function setBucket(array $host_map, ?array $forward_map, int $replicas): bool
    {
        return true;
    }

    #[\Override]
    public function quit(): bool
    {
        return true;
    }

    #[\Override]
    public function flushBuffers(): bool
    {
        return true;
    }

    #[\Override]
    public function getLastErrorMessage(): string
    {
        return '';
    }

    #[\Override]
    public function getLastErrorCode(): int
    {
        return 0;
    }

    #[\Override]
    public function getLastErrorErrno(): int
    {
        return 0;
    }

    #[\Override]
    public function getLastDisconnectedServer(): array|false
    {
        return false;
    }

    #[\Override]
    public function getOption(int $option): mixed
    {
        return null;
    }

    #[\Override]
    public function setOption(int $option, mixed $value): bool
    {
        $this->calls[] = ['method' => 'setOption', 'option' => $option, 'value' => $value];
        $this->lastResultCode = MemcachedConstants::RES_SUCCESS;

        return true;
    }

    #[\Override]
    public function setOptions(array $options): bool
    {
        return true;
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return false;
    }

    #[\Override]
    public function isPristine(): bool
    {
        return true;
    }

    #[\Override]
    public function checkKey(string $key): bool
    {
        return '' !== $key;
    }

    #[\Override]
    public function setEncodingKey(string $key): bool
    {
        return false;
    }

    #[\Override]
    public function setSaslAuthData(string $username, #[\SensitiveParameter] string $password): bool
    {
        return false;
    }

    #[\Override]
    public function getStats(?string $type = null): array|false
    {
        return false;
    }

    #[\Override]
    public function getVersion(): array|false
    {
        return false;
    }

    #[\Override]
    public function flush(int $delay = 0): bool
    {
        return true;
    }

    #[\Override]
    public function getAllKeys(): array|false
    {
        return false;
    }

    #[\Override]
    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        $this->calls[] = ['method' => 'get', 'key' => $key];
        $this->lastResultCode = [] === $this->getResultCodeStream
            ? MemcachedConstants::RES_SUCCESS
            : array_shift($this->getResultCodeStream);

        return $this->getPayload;
    }

    #[\Override]
    public function getByKey(string $server_key, string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        return $this->get($key, $cache_cb, $get_flags);
    }

    #[\Override]
    public function getMulti(array $keys, int $get_flags = 0): array|false
    {
        return false;
    }

    #[\Override]
    public function getMultiByKey(string $server_key, array $keys, int $get_flags = 0): array|false
    {
        return false;
    }

    #[\Override]
    public function getDelayed(array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        return false;
    }

    #[\Override]
    public function getDelayedByKey(string $server_key, array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        return false;
    }

    #[\Override]
    public function fetch(): array|false
    {
        return false;
    }

    #[\Override]
    public function fetchAll(): array|false
    {
        return false;
    }

    #[\Override]
    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->calls[] = ['method' => 'set', 'key' => $key, 'value' => $value, 'expiration' => $expiration];

        $return = [] === $this->setReturnStream ? true : array_shift($this->setReturnStream);
        $this->lastResultCode = [] === $this->setResultCodeStream
            ? ($return ? MemcachedConstants::RES_SUCCESS : MemcachedConstants::RES_NOTSTORED)
            : array_shift($this->setResultCodeStream);

        return $return;
    }

    #[\Override]
    public function setByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->set($key, $value, $expiration);
    }

    #[\Override]
    public function touch(string $key, int $expiration = 0): bool
    {
        $this->calls[] = ['method' => 'touch', 'key' => $key, 'expiration' => $expiration];
        $this->lastResultCode = $this->touchReturn ? MemcachedConstants::RES_SUCCESS : MemcachedConstants::RES_FAILURE;

        return $this->touchReturn;
    }

    #[\Override]
    public function touchByKey(string $server_key, string $key, int $expiration = 0): bool
    {
        return $this->touch($key, $expiration);
    }

    #[\Override]
    public function setMulti(array $items, int $expiration = 0): bool
    {
        return true;
    }

    #[\Override]
    public function setMultiByKey(string $server_key, array $items, int $expiration = 0): bool
    {
        return true;
    }

    #[\Override]
    public function add(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->calls[] = ['method' => 'add', 'key' => $key, 'value' => $value, 'expiration' => $expiration];

        $return = [] === $this->addReturnStream ? true : array_shift($this->addReturnStream);
        $this->lastResultCode = [] === $this->addResultCodeStream
            ? ($return ? MemcachedConstants::RES_SUCCESS : MemcachedConstants::RES_NOTSTORED)
            : array_shift($this->addResultCodeStream);

        return $return;
    }

    #[\Override]
    public function addByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->add($key, $value, $expiration);
    }

    #[\Override]
    public function replace(string $key, mixed $value, int $expiration = 0): bool
    {
        return false;
    }

    #[\Override]
    public function replaceByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return false;
    }

    #[\Override]
    public function append(string $key, string $value): bool
    {
        return false;
    }

    #[\Override]
    public function appendByKey(string $server_key, string $key, string $value): bool
    {
        return false;
    }

    #[\Override]
    public function prepend(string $key, string $value): bool
    {
        return false;
    }

    #[\Override]
    public function prependByKey(string $server_key, string $key, string $value): bool
    {
        return false;
    }

    #[\Override]
    public function cas(string|int|float $cas_token, string $key, mixed $value, int $expiration = 0): bool
    {
        return false;
    }

    #[\Override]
    public function casByKey(string|int|float $cas_token, string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return false;
    }

    #[\Override]
    public function delete(string $key, int $time = 0): bool
    {
        $this->calls[] = ['method' => 'delete', 'key' => $key];
        $this->lastResultCode = $this->deleteReturn ? MemcachedConstants::RES_SUCCESS : MemcachedConstants::RES_FAILURE;

        return $this->deleteReturn;
    }

    #[\Override]
    public function deleteByKey(string $server_key, string $key, int $time = 0): bool
    {
        return $this->delete($key, $time);
    }

    #[\Override]
    public function deleteMulti(array $keys, int $time = 0): array
    {
        return [];
    }

    #[\Override]
    public function deleteMultiByKey(string $server_key, array $keys, int $time = 0): array
    {
        return [];
    }

    #[\Override]
    public function increment(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return false;
    }

    #[\Override]
    public function decrement(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return false;
    }

    #[\Override]
    public function incrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return false;
    }

    #[\Override]
    public function decrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return false;
    }
}
