<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Memcached-compatible expiration normalization.
 *
 * Memcached defines:
 *  - {@code expiration <= 0}   → no expiry (the item never expires)
 *  - {@code 0 < expiration <= 60*60*24*30 (2_592_000)}  → "relative" seconds
 *    from now, as a positive duration
 *  - {@code expiration > 2_592_000}  → **absolute** Unix timestamp; the server
 *    interprets the raw integer as the wall-clock time at which the item
 *    becomes stale.
 *
 * The native memcached meta protocol implements that rule server-side via
 * {@code T<expiration>}, so the {@see \PureCache\Memcached\MemcachedClient}
 * can pass the value through unchanged. Other backends (Redis EXPIRE, etc.)
 * only accept relative seconds, so they have to translate "absolute" TTLs
 * back to "seconds from now" — this helper centralises that conversion.
 */
final class Expiration
{
    /**
     * Memcached's 30-day cut-off. Any positive expiration value above this is
     * treated as an absolute Unix timestamp instead of a relative duration.
     */
    public const int MEMCACHED_RELATIVE_LIMIT_SECONDS = 60 * 60 * 24 * 30;

    private function __construct()
    {
    }

    /**
     * Normalises an arbitrary memcached-style expiration value into a relative
     * "seconds from now" TTL suitable for backends that lack the absolute-time
     * convention (Redis EXPIRE, Lua scripts, etc.).
     *
     * @return int|null {@code null} → no expiry; otherwise a positive number of
     *                  seconds. Always {@code >= 1}.
     */
    public static function toRelativeSeconds(int $expiration, ?int $now = null): ?int
    {
        if ($expiration <= 0) {
            return null;
        }

        if ($expiration <= self::MEMCACHED_RELATIVE_LIMIT_SECONDS) {
            return $expiration;
        }

        $reference = $now ?? time();
        $delta = $expiration - $reference;
        if ($delta <= 0) {
            return 1;
        }

        return $delta;
    }
}
