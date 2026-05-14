<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Central option defaults and scalar coercion rules for the PECL-shaped API.
 */
final class ClientOptions
{
    private function __construct()
    {
    }

    /**
     * @return array<int, mixed>
     */
    public static function defaults(): array
    {
        return [
            MemcachedConstants::OPT_COMPRESSION => true,
            MemcachedConstants::OPT_COMPRESSION_TYPE => MemcachedConstants::COMPRESSION_TYPE_FASTLZ,
            MemcachedConstants::OPT_COMPRESSION_LEVEL => 3,
            MemcachedConstants::OPT_SERIALIZER => self::defaultSerializer(),
            MemcachedConstants::OPT_PREFIX_KEY => '',
            MemcachedConstants::OPT_HASH => MemcachedConstants::HASH_DEFAULT,
            MemcachedConstants::OPT_DISTRIBUTION => MemcachedConstants::DISTRIBUTION_MODULA,
            MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE => false,
            MemcachedConstants::OPT_BUFFER_WRITES => false,
            MemcachedConstants::OPT_BINARY_PROTOCOL => false,
            MemcachedConstants::OPT_NO_BLOCK => false,
            MemcachedConstants::OPT_NOREPLY => false,
            MemcachedConstants::OPT_TCP_NODELAY => false,
            MemcachedConstants::OPT_TCP_KEEPALIVE => false,
            MemcachedConstants::OPT_SOCKET_SEND_SIZE => 0,
            MemcachedConstants::OPT_SOCKET_RECV_SIZE => 0,
            MemcachedConstants::OPT_CONNECT_TIMEOUT => 1000,
            MemcachedConstants::OPT_RETRY_TIMEOUT => 0,
            MemcachedConstants::OPT_DEAD_TIMEOUT => 0,
            MemcachedConstants::OPT_SEND_TIMEOUT => 0,
            MemcachedConstants::OPT_RECV_TIMEOUT => 0,
            MemcachedConstants::OPT_POLL_TIMEOUT => 1000,
            MemcachedConstants::OPT_SERVER_FAILURE_LIMIT => 0,
            MemcachedConstants::OPT_REMOVE_FAILED_SERVERS => false,
            MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY => false,
            MemcachedConstants::OPT_VERIFY_KEY => true,
            MemcachedConstants::OPT_USER_FLAGS => -1,
            MemcachedConstants::OPT_STORE_RETRY_COUNT => 0,
            MemcachedConstants::OPT_ITEM_SIZE_LIMIT => 0,
            MemcachedConstants::OPT_NUMBER_OF_REPLICAS => 0,
            MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ => false,
            MemcachedConstants::OPT_ENCODING_MODE => MemcachedConstants::ENCODING_MODE_LIBMEMCACHED,
        ];
    }

    public static function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return '';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function intValue(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * PHP-side equivalent of PECL's {@code zval_get_long()} — the same
     * widening rules ext-memcached relies on for options whose setter is
     * defined in {@code php_memcached.c} as
     * {@code lval = zval_get_long(value)} (e.g. {@code OPT_LIBKETAMA_HASH}).
     *
     * Use this — rather than the stricter {@see intValue()} — when matching
     * PECL behaviour for options that are documented as "accepts an integer"
     * but where the C extension silently coerces everything else (bools,
     * strings, floats, arrays, …). The coercion must never throw: PECL
     * surfaces objects/resources as the conventional {@code 1}/numeric id,
     * not as a fatal error inside {@code setOption()}.
     *
     * Rules (mirror {@code Zend/zend_operators.c::zval_get_long_func()}):
     *  - int/resource → as is
     *  - float       → truncated toward zero
     *  - bool        → 1 or 0
     *  - null        → 0
     *  - string      → leading numeric portion, 0 if non-numeric
     *  - array       → 1 if non-empty, 0 if empty
     *  - object      → 1 (PECL emits an E_NOTICE; we silently coerce)
     */
    public static function peclLongValue(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int) $value;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (null === $value) {
            return 0;
        }

        if (\is_string($value)) {
            return (int) $value;
        }

        if (\is_array($value)) {
            return [] === $value ? 0 : 1;
        }

        if (\is_resource($value)) {
            return (int) $value;
        }

        return 1;
    }

    /**
     * Picks the initial {@code OPT_SERIALIZER} the same way PECL does: igbinary
     * first if its extension is loaded, then msgpack, otherwise PHP's native
     * {@code serialize()}. Apps that switch backends shouldn't see the wire
     * format change underneath them, so the choice has to mirror PECL's
     * documented order — see ext/memcached's {@code php_memc_init_globals()}.
     */
    public static function defaultSerializer(): int
    {
        if (\extension_loaded('igbinary')) {
            return MemcachedConstants::SERIALIZER_IGBINARY;
        }

        if (\extension_loaded('msgpack')) {
            return MemcachedConstants::SERIALIZER_MSGPACK;
        }

        return MemcachedConstants::SERIALIZER_PHP;
    }
}
