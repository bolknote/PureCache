<?php

declare(strict_types=1);

namespace PureCache;

use PureCache\Ignite\IgniteClient;
use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

/**
 * Builds a PECL-shaped cache client for memcached, Redis, Apache Ignite, or
 * app-registered backends.
 *
 * There is no auto-detection by host/port: each backend speaks a different
 * protocol. Choose explicitly via {@see self::create()} (or instantiate
 * {@see MemcachedClient} / {@see RedisClient} / {@see IgniteClient} directly).
 * Additional drivers register with {@see self::register()}.
 */
final class ClientFactory
{
    /**
     * @var array<string, callable(?string, ?callable, ?string): CacheClient>
     */
    private static array $registry = [];

    private function __construct()
    {
    }

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
     * @param string|null $backend {@code null}, empty string, {@code memcached} or {@code mc} → {@see MemcachedClient}; {@code redis} → {@see RedisClient}; {@code ignite} or {@code ig} → {@see IgniteClient}; otherwise a {@see self::register()}d name
     */
    public static function create(
        ?string $backend = null,
        ?string $persistentId = null,
        ?callable $callback = null,
        ?string $connection_str = null,
    ): CacheClient {
        $key = self::normalizeName($backend ?? '');

        return match (true) {
            '' === $key, 'memcached' === $key, 'mc' === $key => new MemcachedClient($persistentId, $callback, $connection_str),
            'redis' === $key => new RedisClient($persistentId, $callback, $connection_str),
            'ignite' === $key, 'ig' === $key => new IgniteClient($persistentId, $callback, $connection_str),
            isset(self::$registry[$key]) => (self::$registry[$key])($persistentId, $callback, $connection_str),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported cache backend %s. Use memcached, mc, redis, ignite, ig, omit for memcached, or register a custom backend.', null === $backend || '' === $backend ? '(empty)' : $backend)),
        };
    }

    private static function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }

    private static function isBuiltinBackendKey(string $key): bool
    {
        return '' === $key
            || 'memcached' === $key
            || 'mc' === $key
            || 'redis' === $key
            || 'ignite' === $key
            || 'ig' === $key;
    }
}
