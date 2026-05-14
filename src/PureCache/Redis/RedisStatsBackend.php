<?php

declare(strict_types=1);

namespace PureCache\Redis;

/**
 * Minimal Redis surface used by {@see RedisStatsAsMemcached} (INFO, SCAN, OBJECT, DBSIZE).
 *
 * Implemented by {@see NativeRedisClient}; tests may use PHPUnit mocks of this interface.
 */
interface RedisStatsBackend
{
    /**
     * @return array<string, mixed>
     */
    public function info(?string $section = null): array;

    public function dbsize(): int;

    /**
     * @param array{MATCH?: string, COUNT?: int} $options
     *
     * @return array{0: int, 1: list<string>}
     */
    public function scan(int $cursor, array $options): array;

    public function object(string $subcommand, string $key): mixed;
}
