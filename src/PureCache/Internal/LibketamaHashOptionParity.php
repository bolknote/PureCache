<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Detects how the loaded ext-memcached build surfaces {@code OPT_LIBKETAMA_HASH}.
 *
 * Builds diverge in two places: whether {@code setOption(OPT_LIBKETAMA_HASH)}
 * surfaces the coerced value before {@code OPT_LIBKETAMA_COMPATIBLE} is enabled,
 * and whether the getter keeps tracking {@code OPT_HASH} after compatible mode
 * plus a {@code setOption(OPT_LIBKETAMA_HASH)} call (ext-memcached 3.4.x).
 */
final class LibketamaHashOptionParity
{
    private static ?bool $peclAliasesOptHashIntoKetamaGetter = null;

    /**
     * Detects whether the loaded ext-memcached/libmemcached pair makes
     * {@code getOption(OPT_LIBKETAMA_HASH)} read-alias {@code OPT_HASH}, or
     * keeps {@code MEMCACHED_BEHAVIOR_HASH} and
     * {@code MEMCACHED_BEHAVIOR_KETAMA_HASH} as two independent slots.
     *
     * Older macOS libmemcached builds alias (the getter always tracks
     * {@code OPT_HASH}, even after {@code setOption(OPT_LIBKETAMA_HASH)} is
     * called). Linux libmemcached 1.x keeps the slots independent and
     * returns {@code HASH_DEFAULT} until the ketama dial is explicitly
     * written.
     */
    public static function peclAliasesOptHashIntoKetamaGetter(): bool
    {
        if (null !== self::$peclAliasesOptHashIntoKetamaGetter) {
            return self::$peclAliasesOptHashIntoKetamaGetter;
        }

        if (!\extension_loaded('memcached') || !class_exists(\Memcached::class, false)) {
            return self::$peclAliasesOptHashIntoKetamaGetter = false;
        }

        $client = new \Memcached();
        $client->setOption(\Memcached::OPT_HASH, MemcachedConstants::HASH_CRC);

        return self::$peclAliasesOptHashIntoKetamaGetter =
            MemcachedConstants::HASH_CRC === $client->getOption(\Memcached::OPT_LIBKETAMA_HASH);
    }

    /**
     * Resolved {@code getOption(OPT_LIBKETAMA_HASH)} for a PureCache client:
     * read-aliases {@code OPT_HASH} on libmemcached builds that fold the two
     * behaviors together, otherwise returns the stored ketama dial.
     */
    public static function resolveLibketamaHashGetter(ClientCoreState $core): int
    {
        if (self::peclAliasesOptHashIntoKetamaGetter()) {
            return $core->optionInt(MemcachedConstants::OPT_HASH, MemcachedConstants::HASH_DEFAULT);
        }

        return $core->optionInt(MemcachedConstants::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_DEFAULT);
    }

    /**
     * libmemcached's hashkit setter accepts every documented {@code HASH_*}
     * id and silently maps out-of-range / negative values back to
     * {@code HASH_DEFAULT}. PureCache mirrors that normalization in its
     * stored {@code OPT_LIBKETAMA_HASH} slot so the getter matches PECL.
     */
    public static function normalizeStoredKetamaHash(int $hash): int
    {
        return \in_array($hash, [
            MemcachedConstants::HASH_DEFAULT,
            MemcachedConstants::HASH_MD5,
            MemcachedConstants::HASH_CRC,
            MemcachedConstants::HASH_FNV1_64,
            MemcachedConstants::HASH_FNV1A_64,
            MemcachedConstants::HASH_FNV1_32,
            MemcachedConstants::HASH_FNV1A_32,
            MemcachedConstants::HASH_MURMUR,
        ], true) ? $hash : MemcachedConstants::HASH_DEFAULT;
    }

    /**
     * Expected {@code getOption(OPT_LIBKETAMA_HASH)} after anchoring {@code OPT_HASH}
     * and calling {@code setOption(OPT_LIBKETAMA_HASH, $value)} on a fresh client.
     */
    public static function expectedGetterAfterLibketamaSetter(
        mixed $value,
        int $anchoredHash = MemcachedConstants::HASH_MURMUR,
    ): int {
        if (\extension_loaded('memcached') && class_exists(\Memcached::class, false)) {
            $client = new \Memcached();
            $client->setOption(\Memcached::OPT_HASH, $anchoredHash);
            $client->setOption(\Memcached::OPT_LIBKETAMA_HASH, $value);

            return self::readPeclOptionAsInt($client, \Memcached::OPT_LIBKETAMA_HASH, $anchoredHash);
        }

        // No ext-memcached loaded: PureCache stores the normalized coerced
        // value in its own OPT_LIBKETAMA_HASH slot and the getter reads it
        // back (the no-ext probe answers "independent slots").
        return self::normalizeStoredKetamaHash(ClientOptions::peclLongValue($value));
    }

    private static function readPeclOptionAsInt(\Memcached $client, int $option, int $fallback): int
    {
        return ClientOptions::intValue($client->getOption($option)) ?? $fallback;
    }
}
