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
