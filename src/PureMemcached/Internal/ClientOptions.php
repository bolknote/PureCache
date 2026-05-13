<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

/**
 * Central option defaults and scalar coercion rules for the PECL-shaped API.
 */
final class ClientOptions
{
    /**
     * @return array<int, mixed>
     */
    public static function defaults(): array
    {
        return [
            MemcachedConstants::OPT_COMPRESSION => true,
            MemcachedConstants::OPT_COMPRESSION_TYPE => MemcachedConstants::COMPRESSION_TYPE_FASTLZ,
            MemcachedConstants::OPT_COMPRESSION_LEVEL => 3,
            MemcachedConstants::OPT_SERIALIZER => MemcachedConstants::SERIALIZER_PHP,
            MemcachedConstants::OPT_PREFIX_KEY => '',
            MemcachedConstants::OPT_HASH => MemcachedConstants::HASH_DEFAULT,
            MemcachedConstants::OPT_DISTRIBUTION => MemcachedConstants::DISTRIBUTION_MODULA,
            MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE => false,
            MemcachedConstants::OPT_BUFFER_WRITES => false,
            MemcachedConstants::OPT_BINARY_PROTOCOL => false,
            MemcachedConstants::OPT_NO_BLOCK => false,
            MemcachedConstants::OPT_NOREPLY => false,
            MemcachedConstants::OPT_TCP_NODELAY => true,
            MemcachedConstants::OPT_CONNECT_TIMEOUT => 1000,
            MemcachedConstants::OPT_RETRY_TIMEOUT => 0,
            MemcachedConstants::OPT_DEAD_TIMEOUT => 0,
            MemcachedConstants::OPT_SEND_TIMEOUT => 0,
            MemcachedConstants::OPT_RECV_TIMEOUT => 0,
            MemcachedConstants::OPT_POLL_TIMEOUT => 1000,
            MemcachedConstants::OPT_SERVER_FAILURE_LIMIT => 0,
            MemcachedConstants::OPT_REMOVE_FAILED_SERVERS => false,
            MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY => false,
            MemcachedConstants::OPT_USER_FLAGS => -1,
            MemcachedConstants::OPT_STORE_RETRY_COUNT => 0,
            MemcachedConstants::OPT_ITEM_SIZE_LIMIT => 0,
            MemcachedConstants::OPT_NUMBER_OF_REPLICAS => 0,
            MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ => false,
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
}
