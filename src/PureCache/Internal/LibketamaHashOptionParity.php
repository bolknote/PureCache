<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Detects how the loaded ext-memcached build surfaces {@code OPT_LIBKETAMA_HASH}.
 *
 * Current PECL (3.4.x) keeps {@code getOption(OPT_LIBKETAMA_HASH)} aliased to
 * {@code OPT_HASH} after {@code setOption(OPT_LIBKETAMA_HASH)} once
 * {@code OPT_LIBKETAMA_COMPATIBLE} has been enabled. Older builds left the
 * ketama dial readable as the value last passed to the setter.
 */
final class LibketamaHashOptionParity
{
    private static ?bool $setterUpdatesStoredKetamaGetter = null;

    public static function setterUpdatesStoredKetamaGetter(): bool
    {
        if (null !== self::$setterUpdatesStoredKetamaGetter) {
            return self::$setterUpdatesStoredKetamaGetter;
        }

        if (!\extension_loaded('memcached') || !class_exists(\Memcached::class, false)) {
            self::$setterUpdatesStoredKetamaGetter = false;

            return self::$setterUpdatesStoredKetamaGetter;
        }

        $client = new \Memcached();
        $client->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $client->setOption(\Memcached::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_MURMUR);

        self::$setterUpdatesStoredKetamaGetter =
            MemcachedConstants::HASH_MURMUR === $client->getOption(\Memcached::OPT_LIBKETAMA_HASH)
            && MemcachedConstants::HASH_MD5 === $client->getOption(\Memcached::OPT_HASH);

        return self::$setterUpdatesStoredKetamaGetter;
    }
}
