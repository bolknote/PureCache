<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\Redis\Internal\RedisInfoReplyFlatten;

/**
 * Maps Redis INFO / lightweight SCAN probes into memcached 1.6.x-shaped {@code stats} maps
 * (flat {@code name => int|float|string}) so callers can reuse PECL-style expectations.
 *
 * Values are best-effort: Redis has different accounting than memcached; keys aim to mirror
 * memcached 1.6 general stats plus {@code stats items|slabs|sizes} shapes.
 */
final class RedisStatsAsMemcached
{
    /**
     * Default max memory if Redis has no limit (8 GB).
     */
    private const int DEFAULT_MAX_MEMORY = 8_589_934_592;

    private function __construct()
    {
    }

    /** @var list<string> */
    private const array GENERAL_STAT_NAMES = [
        'pid', 'uptime', 'time', 'version', 'libevent', 'pointer_size', 'rusage_user', 'rusage_system',
        'max_connections', 'curr_connections', 'total_connections', 'rejected_connections', 'connection_structures',
        'response_obj_oom', 'response_obj_count', 'response_obj_bytes', 'read_buf_count', 'read_buf_bytes',
        'read_buf_bytes_free', 'read_buf_oom', 'reserved_fds', 'cmd_get', 'cmd_set', 'cmd_flush', 'cmd_touch',
        'cmd_meta', 'get_hits', 'get_misses', 'get_expired', 'get_flushed', 'delete_misses', 'delete_hits',
        'incr_misses', 'incr_hits', 'decr_misses', 'decr_hits', 'cas_misses', 'cas_hits', 'cas_badval',
        'touch_hits', 'touch_misses', 'store_too_large', 'store_no_memory', 'auth_cmds', 'auth_errors',
        'bytes_read', 'bytes_written', 'limit_maxbytes', 'accepting_conns', 'listen_disabled_num',
        'time_in_listen_disabled_us', 'threads', 'conn_yields', 'hash_power_level', 'hash_bytes',
        'hash_is_expanding', 'slab_reassign_rescues', 'slab_reassign_chunk_rescues', 'slab_reassign_inline_reclaim',
        'slab_reassign_busy_items', 'slab_reassign_busy_deletes', 'slab_reassign_busy_nomem',
        'slab_reassign_last_busy_status', 'slab_reassign_running', 'slabs_moved', 'lru_crawler_running',
        'lru_crawler_starts', 'lru_maintainer_juggles', 'malloc_fails', 'log_worker_dropped', 'log_worker_written',
        'log_watcher_skipped', 'log_watcher_sent', 'log_watchers', 'unexpected_napi_ids', 'round_robin_fallback',
        'bytes', 'curr_items', 'total_items', 'slab_global_page_pool', 'expired_unfetched', 'evicted_unfetched',
        'evicted_active', 'evictions', 'reclaimed', 'crawler_reclaimed', 'crawler_items_checked', 'lrutail_reflocked',
        'moves_to_cold', 'moves_to_warm', 'moves_within_lru', 'direct_reclaims', 'lru_bumps_dropped',
    ];

    /** @var list<string> */
    private const array ITEMS_SUFFIXES = [
        'number', 'number_hot', 'number_warm', 'number_cold', 'number_temp', 'age_hot', 'age_warm', 'age',
        'mem_requested', 'evicted', 'evicted_nonzero', 'evicted_time', 'outofmemory', 'tailrepairs', 'reclaimed',
        'expired_unfetched', 'evicted_unfetched', 'evicted_active', 'crawler_reclaimed', 'crawler_items_checked',
        'lrutail_reflocked', 'moves_to_cold', 'moves_to_warm', 'moves_within_lru', 'direct_reclaims',
        'hits_to_hot', 'hits_to_warm', 'hits_to_cold', 'hits_to_temp',
    ];

    /** @var list<string> */
    private const array SLAB1_SUFFIXES = [
        'chunk_size', 'chunks_per_page', 'total_pages', 'total_chunks', 'used_chunks', 'free_chunks',
        'free_chunks_end', 'get_hits', 'cmd_set', 'delete_hits', 'incr_hits', 'decr_hits', 'cas_hits', 'cas_badval',
        'touch_hits',
    ];

    /**
     * @return array<string, int|float|string>
     */
    public static function general(RedisStatsBackend $redis): array
    {
        $info = self::getRedisInfo($redis);

        $out = array_fill_keys(self::GENERAL_STAT_NAMES, 0);

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
        $out['limit_maxbytes'] = $maxmem > 0 ? $maxmem : self::DEFAULT_MAX_MEMORY;

        $out['accepting_conns'] = 1;
        $out['threads'] = max(1, self::intFrom($info['io_threads_active'] ?? null, 1));
        $out['hash_power_level'] = 16;

        $out['curr_items'] = $redis->dbsize();
        $out['total_items'] = $out['curr_items'];

        $out['evictions'] = self::intFrom($info['evicted_keys'] ?? null);
        $out['reclaimed'] = self::intFrom($info['expired_keys'] ?? null);

        return $out;
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function items(RedisStatsBackend $redis, string $keyPattern): array
    {
        if ('*' === $keyPattern || '' === $keyPattern) {
            $count = $redis->dbsize();
            $firstKey = null;
        } else {
            $scan = self::scanCountAndFirstKey($redis, $keyPattern, 1000);
            $count = $scan['count'];
            $firstKey = $scan['firstKey'];
        }

        if (0 === $count) {
            return [];
        }

        $memRequested = self::estimateMemRequestedBytes($redis, $count);
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
            array_map(static fn (string $s): string => 'items:1:'.$s, self::ITEMS_SUFFIXES),
            0
        );

        $out['items:1:number'] = $count;
        $out['items:1:number_cold'] = $count;
        $out['items:1:age'] = $age;
        $out['items:1:mem_requested'] = $memRequested;

        return $out;
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function slabs(RedisStatsBackend $redis, int $pmKeyCount): array
    {
        $info = self::getRedisInfo($redis);

        $usedMemory = self::intFrom($info['used_memory'] ?? null);
        $out = [];
        $out['active_slabs'] = $pmKeyCount > 0 ? 1 : 0;
        $out['total_malloced'] = $usedMemory;

        if ($pmKeyCount <= 0) {
            return $out;
        }

        $chunkSize = 1_048_576;
        $usedChunks = $pmKeyCount;
        $freeChunks = max(0, 10_000 - $usedChunks);
        $totalChunks = max(1, $usedChunks + $freeChunks);

        $out += array_fill_keys(
            array_map(static fn (string $s): string => '1:'.$s, self::SLAB1_SUFFIXES),
            0
        );

        $out['1:chunk_size'] = $chunkSize;
        $out['1:chunks_per_page'] = $totalChunks;
        $out['1:total_pages'] = 1;
        $out['1:total_chunks'] = $totalChunks;
        $out['1:used_chunks'] = $usedChunks;
        $out['1:free_chunks'] = $freeChunks;
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
     * @return array{count: int, firstKey: ?string}
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
                break;
            }
        } while (0 !== $cursor);

        return ['count' => $count, 'firstKey' => $firstKey];
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

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function estimateMemRequestedBytes(RedisStatsBackend $redis, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        try {
            $info = self::getRedisInfo($redis);
            $usedMemory = self::intFrom($info['used_memory'] ?? null);
            $dbSize = $redis->dbsize();

            if ($dbSize > 0 && $usedMemory > 0) {
                $avgSize = $usedMemory / $dbSize;

                return (int) min(\PHP_INT_MAX, $avgSize * $count);
            }
        } catch (\Exception) {
        }

        return $count * 256;
    }
}
