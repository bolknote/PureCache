<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Shared memcached 1.6.x {@code stats} field names for Redis and Ignite adapters.
 *
 * @internal
 */
final class MemcachedStatsSchema
{
    public const int DEFAULT_MAX_MEMORY = 8_589_934_592;

    /** Conservative per-key bytes when memory cannot be estimated from INFO. */
    public const int PER_KEY_MEMORY_FALLBACK_BYTES = 256;

    /** Upper bound on Redis {@code SCAN} cursor steps for prefix key counts. */
    public const int SCAN_MAX_ITERATIONS = 100_000;

    /** Synthetic {@code slabs} {@code chunk_size} for Redis (not a real slab class). */
    public const int SYNTHETIC_SLAB_CHUNK_SIZE = 1_048_576;

    /** @var list<string> */
    public const array GENERAL_STAT_NAMES = [
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
    public const array ITEMS_SUFFIXES = [
        'number', 'number_hot', 'number_warm', 'number_cold', 'number_temp', 'age_hot', 'age_warm', 'age',
        'mem_requested', 'evicted', 'evicted_nonzero', 'evicted_time', 'outofmemory', 'tailrepairs', 'reclaimed',
        'expired_unfetched', 'evicted_unfetched', 'evicted_active', 'crawler_reclaimed', 'crawler_items_checked',
        'lrutail_reflocked', 'moves_to_cold', 'moves_to_warm', 'moves_within_lru', 'direct_reclaims',
        'hits_to_hot', 'hits_to_warm', 'hits_to_cold', 'hits_to_temp',
    ];

    /** @var list<string> */
    public const array SLAB1_SUFFIXES = [
        'chunk_size', 'chunks_per_page', 'total_pages', 'total_chunks', 'used_chunks', 'free_chunks',
        'free_chunks_end', 'get_hits', 'cmd_set', 'delete_hits', 'incr_hits', 'decr_hits', 'cas_hits', 'cas_badval',
        'touch_hits',
    ];

    private function __construct()
    {
    }
}
