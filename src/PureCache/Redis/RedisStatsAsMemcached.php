<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\Internal\MemcachedStatsSchema;
use PureCache\Redis\Internal\RedisInfoReplyFlatten;

/**
 * Maps Redis INFO / lightweight SCAN probes into memcached 1.6.x-shaped {@code stats} maps
 * (flat {@code name => int|float|string}) so callers can reuse PECL-style expectations.
 *
 * Values are best-effort: Redis has different accounting than memcached; keys aim to mirror
 * memcached 1.6 general stats plus {@code stats items|slabs|sizes} shapes.
 *
 * Prefix key counts use {@see scanKeys()} with a shared iteration cap; when the cap is hit,
 * {@code complete} is false and {@code mem_requested} uses a conservative per-key fallback.
 *
 * @psalm-import-type RedisReply from \PureCache\Internal\PsalmTypes
 *
 * @psalm-suppress MixedAssignment
 */
final class RedisStatsAsMemcached
{
    private function __construct()
    {
    }

    /**
     * @return array{count: int, firstKey: ?string, complete: bool}
     */
    public static function scanKeys(RedisStatsBackend $redis, string $pattern): array
    {
        return self::scanCountAndFirstKey($redis, $pattern, MemcachedStatsSchema::SCAN_MAX_ITERATIONS);
    }

    /**
     * @param array{count: int, firstKey: ?string, complete: bool}|null $keyScan when null, resolved from {@code $keyPattern} via {@see resolveKeyScan()}
     *
     * @return array<string, int|float|string>
     */
    public static function general(RedisStatsBackend $redis, string $keyPattern = '*', ?array $keyScan = null): array
    {
        $info = self::getRedisInfo($redis);
        $scan = self::resolveKeyScan($redis, $keyPattern, $keyScan);

        $out = array_fill_keys(MemcachedStatsSchema::GENERAL_STAT_NAMES, 0);

        $out['version'] = $info['redis_version'] ?? 'unknown';
        $out['libevent'] = 'redis';
        $out['slab_reassign_last_busy_status'] = 'none';

        $out['pid'] = self::intFrom($info['process_id'] ?? null);
        $out['uptime'] = self::intFrom($info['uptime_in_seconds'] ?? null);
        $out['time'] = time();
        $out['pointer_size'] = 64;

        $out['max_connections'] = self::intFrom($info['maxclients'] ?? null, 10000);
        $out['curr_connections'] = self::intFrom($info['connected_clients'] ?? null);
        $out['total_connections'] = self::intFrom($info['total_connections_received'] ?? null);
        $out['rejected_connections'] = self::intFrom($info['rejected_connections'] ?? null);

        $out['cmd_get'] = self::cmdstatCalls($info, 'hgetall')
            + self::cmdstatCalls($info, 'get')
            + self::cmdstatCalls($info, 'mget');
        $out['cmd_set'] = self::cmdstatCalls($info, 'set')
            + self::cmdstatCalls($info, 'mset')
            + self::cmdstatCalls($info, 'hset')
            + self::cmdstatCalls($info, 'hmset');
        $out['cmd_flush'] = self::cmdstatCalls($info, 'flushdb') + self::cmdstatCalls($info, 'flushall');
        $out['cmd_touch'] = self::cmdstatCalls($info, 'touch')
            + self::cmdstatCalls($info, 'expire')
            + self::cmdstatCalls($info, 'pexpire')
            + self::cmdstatCalls($info, 'expireat')
            + self::cmdstatCalls($info, 'pexpireat');

        $out['get_hits'] = self::intFrom($info['keyspace_hits'] ?? null);
        $out['get_misses'] = self::intFrom($info['keyspace_misses'] ?? null);
        $out['delete_hits'] = self::cmdstatCalls($info, 'del') + self::cmdstatCalls($info, 'unlink');
        $out['incr_hits'] = self::cmdstatCalls($info, 'incr') + self::cmdstatCalls($info, 'incrby');
        $out['decr_hits'] = self::cmdstatCalls($info, 'decr') + self::cmdstatCalls($info, 'decrby');

        $out['bytes_read'] = self::intFrom($info['total_net_input_bytes'] ?? null);
        $out['bytes_written'] = self::intFrom($info['total_net_output_bytes'] ?? null);
        $out['bytes'] = self::intFrom($info['used_memory'] ?? null);

        $maxmem = self::intFrom($info['maxmemory'] ?? null);
        $out['limit_maxbytes'] = $maxmem > 0 ? $maxmem : MemcachedStatsSchema::DEFAULT_MAX_MEMORY;

        $out['accepting_conns'] = 1;
        $out['threads'] = max(1, self::intFrom($info['io_threads_active'] ?? null, 1));
        $out['hash_power_level'] = 16;

        $itemCount = $scan['count'];
        $out['curr_items'] = $itemCount;
        $out['total_items'] = $itemCount;

        $out['evictions'] = self::intFrom($info['evicted_keys'] ?? null);
        $out['reclaimed'] = self::intFrom($info['expired_keys'] ?? null);

        return $out;
    }

    /**
     * @param array{count: int, firstKey: ?string, complete: bool}|null $keyScan when null, resolved from {@code $keyPattern}
     *
     * @return array<string, int|float|string>
     */
    public static function items(RedisStatsBackend $redis, string $keyPattern, ?array $keyScan = null): array
    {
        $scan = self::resolveKeyScan($redis, $keyPattern, $keyScan);
        $count = $scan['count'];
        $firstKey = $scan['firstKey'];

        if (0 === $count) {
            return [];
        }

        $memRequested = self::estimateMemRequestedBytes($redis, $count, $scan['complete']);
        $age = 0;
        if (\is_string($firstKey) && '' !== $firstKey) {
            try {
                $idle = $redis->object('IDLETIME', $firstKey);
                if (\is_int($idle)) {
                    $age = $idle;
                }
            } catch (\Throwable) {
                $age = 0;
            }
        }

        $out = array_fill_keys(
            array_map(static fn (string $s): string => 'items:1:'.$s, MemcachedStatsSchema::ITEMS_SUFFIXES),
            0
        );

        $out['items:1:number'] = $count;
        $out['items:1:number_cold'] = $count;
        $out['items:1:age'] = $age;
        $out['items:1:mem_requested'] = $memRequested;

        return $out;
    }

    /**
     * Synthetic single-slab view for PECL-shaped {@code stats slabs}; not Redis memory classes.
     *
     * @param array{count: int, firstKey: ?string, complete: bool} $keyScan from {@see scanKeys()}
     *
     * @return array<string, int|float|string>
     */
    public static function slabs(RedisStatsBackend $redis, array $keyScan): array
    {
        $info = self::getRedisInfo($redis);
        $pmKeyCount = $keyScan['count'];

        $usedMemory = self::intFrom($info['used_memory'] ?? null);
        $out = [];
        $out['active_slabs'] = $pmKeyCount > 0 ? 1 : 0;
        $out['total_malloced'] = $usedMemory;

        if ($pmKeyCount <= 0) {
            return $out;
        }

        $chunkSize = MemcachedStatsSchema::SYNTHETIC_SLAB_CHUNK_SIZE;
        $usedChunks = $pmKeyCount;
        $totalChunks = max(1, $usedChunks);

        $out += array_fill_keys(
            array_map(static fn (string $s): string => '1:'.$s, MemcachedStatsSchema::SLAB1_SUFFIXES),
            0
        );

        $out['1:chunk_size'] = $chunkSize;
        $out['1:chunks_per_page'] = $totalChunks;
        $out['1:total_pages'] = 1;
        $out['1:total_chunks'] = $totalChunks;
        $out['1:used_chunks'] = $usedChunks;
        $out['1:free_chunks'] = 0;
        $out['1:free_chunks_end'] = 0;

        $out['1:get_hits'] = self::cmdstatCalls($info, 'hgetall');
        $out['1:cmd_set'] = self::cmdstatCalls($info, 'hset');
        $out['1:delete_hits'] = self::cmdstatCalls($info, 'del') + self::cmdstatCalls($info, 'unlink');
        $out['1:incr_hits'] = self::cmdstatCalls($info, 'incrby');
        $out['1:decr_hits'] = self::cmdstatCalls($info, 'decrby');
        $out['1:cas_hits'] = 0;
        $out['1:cas_badval'] = 0;
        $out['1:touch_hits'] = self::cmdstatCalls($info, 'touch')
            + self::cmdstatCalls($info, 'expire')
            + self::cmdstatCalls($info, 'pexpire');

        return $out;
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function sizes(): array
    {
        return [
            'sizes_status' => 'disabled',
        ];
    }

    /**
     * @return array{count: int, firstKey: ?string, complete: bool}
     */
    public static function scanCountAndFirstKey(RedisStatsBackend $redis, string $pattern, int $maxIterations): array
    {
        $cursor = 0;
        $count = 0;
        $firstKey = null;
        $iter = 0;

        do {
            $scan = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 500]);
            $cursor = $scan[0];

            foreach ($scan[1] as $k) {
                if ('' === $k) {
                    continue;
                }

                ++$count;
                if (null === $firstKey) {
                    $firstKey = $k;
                }
            }

            ++$iter;
            if ($iter >= $maxIterations) {
                return ['count' => $count, 'firstKey' => $firstKey, 'complete' => false];
            }
        } while (0 !== $cursor);

        return ['count' => $count, 'firstKey' => $firstKey, 'complete' => true];
    }

    /**
     * @param array{count: int, firstKey: ?string, complete: bool}|null $keyScan
     *
     * @return array{count: int, firstKey: ?string, complete: bool}
     */
    private static function resolveKeyScan(RedisStatsBackend $redis, string $keyPattern, ?array $keyScan): array
    {
        if (null !== $keyScan) {
            return $keyScan;
        }

        if ('*' === $keyPattern || '' === $keyPattern) {
            return ['count' => $redis->dbsize(), 'firstKey' => null, 'complete' => true];
        }

        return self::scanKeys($redis, $keyPattern);
    }

    /**
     * @return array<string, string>
     */
    private static function getRedisInfo(RedisStatsBackend $redis): array
    {
        return RedisInfoReplyFlatten::toStringMap($redis->info());
    }

    /**
     * @param array<string, string> $info
     */
    private static function cmdstatCalls(array $info, string $command): int
    {
        $key = 'cmdstat_'.$command;
        if (!isset($info[$key])) {
            return 0;
        }

        $raw = $info[$key];
        if (1 === preg_match('/\bcalls=(\d+)/', $raw, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private static function intFrom(mixed $value, int $default = 0): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function estimateMemRequestedBytes(RedisStatsBackend $redis, int $count, bool $scanComplete): int
    {
        if ($count <= 0) {
            return 0;
        }

        if (!$scanComplete) {
            return $count * MemcachedStatsSchema::PER_KEY_MEMORY_FALLBACK_BYTES;
        }

        try {
            $info = self::getRedisInfo($redis);
            $usedMemory = self::intFrom($info['used_memory'] ?? null);
            $dbSize = $redis->dbsize();

            if ($dbSize > 0 && $usedMemory > 0) {
                $avgSize = (float) $usedMemory / (float) $dbSize;

                return (int) min((float) \PHP_INT_MAX, $avgSize * (float) $count);
            }
        } catch (\Throwable) {
        }

        return $count * MemcachedStatsSchema::PER_KEY_MEMORY_FALLBACK_BYTES;
    }
}
