<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Shared read/write size policy for {@see MemcachedConstants::OPT_ITEM_SIZE_LIMIT}.
 *
 * Writes reject payloads above the configured limit in
 * {@see \PureCache\AbstractCacheClient::encodeForStore()}. Reads apply the same
 * limit when {@code > 0}, and always enforce {@see ABSOLUTE_MAX_BYTES} on the
 * wire so a hostile server cannot force multi-gigabyte allocations.
 */
final class ItemSizeGuard
{
    /** Hard ceiling for any single item body read from a backend (64 MiB). */
    public const int ABSOLUTE_MAX_BYTES = 64 * 1024 * 1024;

    private function __construct()
    {
    }

    /**
     * Maximum bytes allowed for one item body on read paths.
     *
     * When {@code $optItemSizeLimit} is {@code 0} (PECL default = unlimited for
     * writes), reads still cap at {@see ABSOLUTE_MAX_BYTES}.
     */
    public static function effectiveReadLimit(int $optItemSizeLimit): int
    {
        if ($optItemSizeLimit > 0) {
            return min($optItemSizeLimit, self::ABSOLUTE_MAX_BYTES);
        }

        return self::ABSOLUTE_MAX_BYTES;
    }

    public static function exceedsReadLimit(int $byteLength, int $optItemSizeLimit): bool
    {
        return $byteLength > self::effectiveReadLimit($optItemSizeLimit);
    }

    /**
     * Whether a meta {@code VA <size>} declaration must be rejected before reading the body.
     */
    public static function rejectOversizedVa(int $declaredSize, int $optItemSizeLimit): bool
    {
        return self::exceedsReadLimit($declaredSize, $optItemSizeLimit);
    }

    /**
     * Compare a declared body size against an already-resolved read ceiling
     * (for example {@see MetaReader}'s {@code maxBodyBytes}).
     */
    public static function rejectOversizedDeclaredBody(int $declaredSize, int $maxAllowedBytes): bool
    {
        return $declaredSize > $maxAllowedBytes;
    }

    /**
     * @return int|null {@see MemcachedConstants::RES_E2BIG} when over limit, else {@code null}
     */
    public static function assertWithinLimit(int $byteLength, int $optItemSizeLimit): ?int
    {
        return self::readLimitResultCode($byteLength, $optItemSizeLimit);
    }

    /**
     * @return int|null {@see MemcachedConstants::RES_E2BIG} when over limit, else {@code null}
     */
    public static function readLimitResultCode(int $byteLength, int $optItemSizeLimit): ?int
    {
        return self::exceedsReadLimit($byteLength, $optItemSizeLimit)
            ? MemcachedConstants::RES_E2BIG
            : null;
    }
}
