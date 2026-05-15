<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteStatsSnapshot;
use PureCache\Internal\MemcachedStatsSchema;

/**
 * Folds {@see NativeIgniteClient} bookkeeping plus a couple of cheap Ignite
 * queries into memcached 1.6.x-shaped {@code stats} maps (flat {@code name =>
 * int|float|string}). Mirrors {@see \PureCache\Redis\RedisStatsAsMemcached} so
 * PECL-style consumers see the same surface regardless of backend.
 *
 * Apache Ignite's binary thin-client protocol exposes no JVM/heap counters, so
 * everything beyond per-connection IO and per-opcode call counts is reported
 * as zero. Counter names that memcached publishes are still emitted (zeroed)
 * so client code probing for them does not crash on missing array keys.
 */
final class IgniteStatsAsMemcached
{
    /**
     * Default Ignite data page size — used as a stand-in {@code chunk_size}
     * for the synthetic single-slab view.
     */
    private const int DEFAULT_PAGE_SIZE = 16_384;

    /** @var list<int> Opcodes that count as {@code cmd_get}. */
    private const array CMD_GET_OPCODES = [
        IgniteProtocol::OP_CACHE_GET,
        IgniteProtocol::OP_CACHE_GET_ALL,
    ];

    /** @var list<int> Opcodes that count as {@code cmd_set}. */
    private const array CMD_SET_OPCODES = [
        IgniteProtocol::OP_CACHE_PUT,
        IgniteProtocol::OP_CACHE_PUT_IF_ABSENT,
        IgniteProtocol::OP_CACHE_REPLACE,
        IgniteProtocol::OP_CACHE_REPLACE_IF_EQUALS,
    ];

    /**
     * @var list<int> Opcodes that count as {@code cmd_touch}. PureCache routes
     *                {@code touch()} through CONTAINS_KEY because Ignite has
     *                no per-entry TTL refresh.
     */
    private const array CMD_TOUCH_OPCODES = [
        IgniteProtocol::OP_CACHE_CONTAINS_KEY,
    ];

    /** @var list<int> Opcodes that count as {@code cmd_flush}. */
    private const array CMD_FLUSH_OPCODES = [
        IgniteProtocol::OP_CACHE_CLEAR,
    ];

    /** @var list<int> Opcodes that count as {@code delete_hits} (call-count only). */
    private const array DELETE_OPCODES = [
        IgniteProtocol::OP_CACHE_REMOVE_KEY,
        IgniteProtocol::OP_CACHE_REMOVE_KEYS,
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function general(IgniteStatsSnapshot $snapshot, int $currItems): array
    {
        $out = array_fill_keys(MemcachedStatsSchema::GENERAL_STAT_NAMES, 0);

        $version = '' !== $snapshot->serverVersion ? $snapshot->serverVersion : 'unknown';
        $now = time();

        $out['version'] = $version;
        $out['libevent'] = 'ignite-thin-client';
        $out['slab_reassign_last_busy_status'] = 'none';

        $out['pid'] = 0;
        $out['uptime'] = $snapshot->connectedAt > 0 ? max(0, $now - $snapshot->connectedAt) : 0;
        $out['time'] = $now;
        $out['pointer_size'] = 64;

        $out['max_connections'] = 0;
        $out['curr_connections'] = 1;
        $out['total_connections'] = 1;
        $out['rejected_connections'] = 0;

        $out['cmd_get'] = $snapshot->sumOpCounts(self::CMD_GET_OPCODES);
        $out['cmd_set'] = $snapshot->sumOpCounts(self::CMD_SET_OPCODES);
        $out['cmd_flush'] = $snapshot->sumOpCounts(self::CMD_FLUSH_OPCODES);
        $out['cmd_touch'] = $snapshot->sumOpCounts(self::CMD_TOUCH_OPCODES);
        $out['cmd_meta'] = 0;

        $out['delete_hits'] = $snapshot->sumOpCounts(self::DELETE_OPCODES);

        $out['bytes_read'] = $snapshot->bytesRead;
        $out['bytes_written'] = $snapshot->bytesWritten;
        $out['bytes'] = 0;

        $out['limit_maxbytes'] = MemcachedStatsSchema::DEFAULT_MAX_MEMORY;
        $out['accepting_conns'] = 1;
        $out['threads'] = 1;
        $out['hash_power_level'] = 16;

        $out['curr_items'] = $currItems;
        $out['total_items'] = $currItems;

        return $out;
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function items(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $out = array_fill_keys(
            array_map(static fn (string $s): string => 'items:1:'.$s, MemcachedStatsSchema::ITEMS_SUFFIXES),
            0
        );

        $out['items:1:number'] = $count;
        $out['items:1:number_cold'] = $count;
        $out['items:1:age'] = 0;
        $out['items:1:mem_requested'] = 0;

        return $out;
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function slabs(IgniteStatsSnapshot $snapshot, int $count): array
    {
        $out = [];
        $out['active_slabs'] = $count > 0 ? 1 : 0;
        $out['total_malloced'] = 0;

        if ($count <= 0) {
            return $out;
        }

        $out += array_fill_keys(
            array_map(static fn (string $s): string => '1:'.$s, MemcachedStatsSchema::SLAB1_SUFFIXES),
            0
        );

        $out['1:chunk_size'] = self::DEFAULT_PAGE_SIZE;
        $out['1:chunks_per_page'] = max(1, $count);
        $out['1:total_pages'] = 1;
        $out['1:total_chunks'] = max(1, $count);
        $out['1:used_chunks'] = $count;
        $out['1:free_chunks'] = 0;
        $out['1:free_chunks_end'] = 0;

        $out['1:get_hits'] = $snapshot->sumOpCounts(self::CMD_GET_OPCODES);
        $out['1:cmd_set'] = $snapshot->sumOpCounts(self::CMD_SET_OPCODES);
        $out['1:delete_hits'] = $snapshot->sumOpCounts(self::DELETE_OPCODES);
        $out['1:incr_hits'] = 0;
        $out['1:decr_hits'] = 0;
        $out['1:cas_hits'] = 0;
        $out['1:cas_badval'] = 0;
        $out['1:touch_hits'] = $snapshot->sumOpCounts(self::CMD_TOUCH_OPCODES);

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function sizes(): array
    {
        return [
            'sizes_status' => 'disabled',
        ];
    }
}
