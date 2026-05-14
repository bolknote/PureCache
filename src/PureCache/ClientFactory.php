<?php

declare(strict_types=1);

namespace PureCache;

use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

/**
 * Builds a PECL-shaped cache client for memcached, Redis, or app-registered backends.
 *
 * There is no auto-detection by host/port: memcached and Redis speak different protocols.
 * Choose explicitly via {@see self::create()} (or instantiate {@see MemcachedClient} /
 * {@see RedisClient} directly). Additional drivers register with {@see self::register()}.
 */
final class ClientFactory
{
    /**
     * @var array<string, callable(?string, ?callable, ?string): CacheClient>
     */
    private static array $registry = [];

    /**
     * @param non-empty-string                                                                           $name    Case-insensitive; must not collide with built-in names {@code memcached}, {@code mc}, {@code redis}
     * @param callable(?string $persistentId, ?callable $callback, ?string $connection_str): CacheClient $factory
     */
    public static function register(string $name, callable $factory): void
    {
        $key = self::normalizeName($name);
        if ('' === $key) {
            throw new \InvalidArgumentException('Backend name cannot be empty.');
        }

        if (self::isBuiltinBackendKey($key)) {
            throw new \InvalidArgumentException(\sprintf('Cannot register over builtin backend %s.', $key));
        }

        self::$registry[$key] = $factory;
    }

    /**
     * @param non-empty-string $name Normalized the same way as {@see self::create()} backend argument
     */
    public static function unregister(string $name): void
    {
        $key = self::normalizeName($name);
        if ('' === $key) {
            throw new \InvalidArgumentException('Backend name cannot be empty.');
        }

        if (self::isBuiltinBackendKey($key)) {
            throw new \InvalidArgumentException(\sprintf('Cannot unregister builtin backend %s.', $key));
        }

        unset(self::$registry[$key]);
    }

    /**
     * Clears all {@see self::register()} entries. Intended for tests; do not use in application bootstrap unless you control all callers.
     */
    public static function resetRegistry(): void
    {
        self::$registry = [];
    }

    /**
     * @param string|null $backend {@code null}, empty string, {@code memcached} or {@code mc} → {@see MemcachedClient}; {@code redis} → {@see RedisClient}; otherwise a {@see self::register()}d name
     */
    public static function create(
        ?string $backend = null,
        ?string $persistentId = null,
        ?callable $callback = null,
        ?string $connection_str = null,
    ): CacheClient {
        $key = self::normalizeName($backend ?? '');

        if ('' === $key || 'memcached' === $key || 'mc' === $key) {
            return new MemcachedClient($persistentId, $callback, $connection_str);
        }

        if ('redis' === $key) {
            return new RedisClient($persistentId, $callback, $connection_str);
        }

        if (isset(self::$registry[$key])) {
            return (self::$registry[$key])($persistentId, $callback, $connection_str);
        }

        throw new \InvalidArgumentException(\sprintf('Unsupported cache backend %s. Use memcached, mc, redis, omit for memcached, or register a custom backend.', null === $backend || '' === $backend ? '(empty)' : $backend));
    }

    private static function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }

    private static function isBuiltinBackendKey(string $key): bool
    {
        return '' === $key || 'memcached' === $key || 'mc' === $key || 'redis' === $key;
    }
}
