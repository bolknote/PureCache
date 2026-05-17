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
    private static ?bool $setterUpdatesStoredKetamaGetter = null;

    private static ?bool $setterSurfacesCoercedGetterWithoutCompat = null;

    /**
     * When false, {@code getOption(OPT_LIBKETAMA_HASH)} should read-alias
     * {@code OPT_HASH}; when true, return the stored ketama dial.
     */
    public static function libketamaGetterUsesStoredSlot(ClientCoreState $core): bool
    {
        $libketamaCompatible = (bool) ($core->options[MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE] ?? false);

        if (!$libketamaCompatible) {
            if (!$core->libketamaHashDialTouched) {
                return false;
            }

            return self::setterSurfacesCoercedGetterWithoutCompat();
        }

        return self::setterUpdatesStoredKetamaGetter();
    }

    /**
     * Resolved {@code getOption(OPT_LIBKETAMA_HASH)} for a PureCache client.
     */
    public static function resolveLibketamaHashGetter(ClientCoreState $core): int
    {
        if (!self::libketamaGetterUsesStoredSlot($core)) {
            return $core->optionInt(MemcachedConstants::OPT_HASH, MemcachedConstants::HASH_DEFAULT);
        }

        return $core->optionInt(MemcachedConstants::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_DEFAULT);
    }

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

    public static function setterSurfacesCoercedGetterWithoutCompat(): bool
    {
        if (null !== self::$setterSurfacesCoercedGetterWithoutCompat) {
            return self::$setterSurfacesCoercedGetterWithoutCompat;
        }

        if (!\extension_loaded('memcached') || !class_exists(\Memcached::class, false)) {
            self::$setterSurfacesCoercedGetterWithoutCompat = false;

            return self::$setterSurfacesCoercedGetterWithoutCompat;
        }

        $client = new \Memcached();
        $client->setOption(\Memcached::OPT_HASH, MemcachedConstants::HASH_MURMUR);
        $client->setOption(\Memcached::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_MD5);

        self::$setterSurfacesCoercedGetterWithoutCompat =
            MemcachedConstants::HASH_MD5 === $client->getOption(\Memcached::OPT_LIBKETAMA_HASH)
            && MemcachedConstants::HASH_MURMUR === $client->getOption(\Memcached::OPT_HASH);

        return self::$setterSurfacesCoercedGetterWithoutCompat;
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

        return self::setterSurfacesCoercedGetterWithoutCompat()
            ? ClientOptions::peclLongValue($value)
            : $anchoredHash;
    }

    private static function readPeclOptionAsInt(\Memcached $client, int $option, int $fallback): int
    {
        return ClientOptions::intValue($client->getOption($option)) ?? $fallback;
    }
}
