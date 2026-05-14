<?php

declare(strict_types=1);

namespace PureCache;

/**
 * PECL {@code \Memcached}-shaped API shared by the text-protocol memcached client and the Redis adapter.
 * Concrete classes extend {@see MemcachedConstants} for {@code RES_*}, {@code OPT_*}, etc.
 *
 * Type-hint this interface when accepting any backend from {@see ClientFactory} or custom drivers.
 */
interface CacheClient
{
    public function getResultCode(): int;

    public function getResultMessage(): string;

    public function addServer(string $host, int $port, int $weight = 0): bool;

    /**
     * @param array<mixed> $servers
     */
    public function addServers(array $servers): bool;

    /**
     * @return list<array{host:string,port:int,type:string,weight:int}>
     */
    public function getServerList(): array;

    /**
     * @return array{host:string,port:int,weight:int}|false
     */
    public function getServerByKey(string $server_key): array|false;

    public function resetServerList(): bool;

    /**
     * @param array<mixed>                  $host_map
     * @param array<int|string, mixed>|null $forward_map
     */
    public function setBucket(array $host_map, ?array $forward_map, int $replicas): bool;

    public function quit(): bool;

    public function flushBuffers(): bool;

    public function getLastErrorMessage(): string;

    public function getLastErrorCode(): int;

    public function getLastErrorErrno(): int;

    /**
     * @return array<string, mixed>|false
     */
    public function getLastDisconnectedServer(): array|false;

    public function getOption(int $option): mixed;

    public function setOption(int $option, mixed $value): bool;

    /**
     * @param array<int|string, mixed> $options
     */
    public function setOptions(array $options): bool;

    public function isPersistent(): bool;

    public function isPristine(): bool;

    public function checkKey(string $key): bool;

    public function setEncodingKey(string $key): bool;

    public function setSaslAuthData(string $username, #[\SensitiveParameter] string $password): bool;

    /**
     * @return array<string, mixed>|false
     */
    public function getStats(?string $type = null): array|false;

    /**
     * @return array<string, mixed>|false
     */
    public function getVersion(): array|false;

    public function flush(int $delay = 0): bool;

    /**
     * @return list<string>|false
     */
    public function getAllKeys(): array|false;

    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed;

    public function getByKey(string $server_key, string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed;

    /**
     * @param list<string>|array<int|string, string> $keys
     *
     * @return array<string, mixed>|false
     */
    public function getMulti(array $keys, int $get_flags = 0): array|false;

    /**
     * @param list<string>|array<int|string, string> $keys
     *
     * @return array<string, mixed>|false
     */
    public function getMultiByKey(string $server_key, array $keys, int $get_flags = 0): array|false;

    /**
     * @param list<string>|array<int|string, string> $keys
     */
    public function getDelayed(array $keys, bool $with_cas = false, ?callable $value_cb = null): bool;

    /**
     * @param list<string>|array<int|string, string> $keys
     */
    public function getDelayedByKey(string $server_key, array $keys, bool $with_cas = false, ?callable $value_cb = null): bool;

    /**
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false;

    /**
     * @return list<array<string, mixed>>|false
     */
    public function fetchAll(): array|false;

    public function set(string $key, mixed $value, int $expiration = 0): bool;

    public function setByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool;

    public function touch(string $key, int $expiration = 0): bool;

    public function touchByKey(string $server_key, string $key, int $expiration = 0): bool;

    /**
     * @param array<string, mixed> $items
     */
    public function setMulti(array $items, int $expiration = 0): bool;

    /**
     * @param array<string, mixed> $items
     */
    public function setMultiByKey(string $server_key, array $items, int $expiration = 0): bool;

    public function add(string $key, mixed $value, int $expiration = 0): bool;

    public function addByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool;

    public function replace(string $key, mixed $value, int $expiration = 0): bool;

    public function replaceByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool;

    public function append(string $key, string $value): bool;

    public function appendByKey(string $server_key, string $key, string $value): bool;

    public function prepend(string $key, string $value): bool;

    public function prependByKey(string $server_key, string $key, string $value): bool;

    public function cas(string|int|float $cas_token, string $key, mixed $value, int $expiration = 0): bool;

    public function casByKey(string|int|float $cas_token, string $server_key, string $key, mixed $value, int $expiration = 0): bool;

    public function delete(string $key, int $time = 0): bool;

    public function deleteByKey(string $server_key, string $key, int $time = 0): bool;

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    public function deleteMulti(array $keys, int $time = 0): array;

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    public function deleteMultiByKey(string $server_key, array $keys, int $time = 0): array;

    public function increment(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false;

    public function decrement(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false;

    public function incrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false;

    public function decrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false;
}
