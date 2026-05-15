<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Backend-agnostic snapshot of a single cache hit.
 *
 * Concrete {@code doGet}-style readers (memcached's {@code MetaValueReader},
 * Redis' {@code HGETALL} wrapper, Ignite's {@code IgniteCacheCodec}, …) all
 * produce one of these so the shared helpers in
 * {@see \PureCache\AbstractCacheClient} can build {@code GET_EXTENDED} arrays
 * and delayed-fetch rows without caring about the underlying wire format.
 *
 * A miss is represented by a backend-specific {@code null} return at the call
 * site, not a sentinel {@code CacheEntry} instance — keeping the value type
 * non-nullable here means code that did receive an entry can safely call
 * {@code $entry->value} etc.
 */
final readonly class CacheEntry
{
    public function __construct(
        public mixed $value,
        public int|string $cas,
        public int $userFlags,
    ) {
    }
}
