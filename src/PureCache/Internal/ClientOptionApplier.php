<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Applies a single PECL {@code setOption()} request to a {@see ClientCoreState} and
 * invokes the supplied {@see OptionEnvironment} when a change must ripple into
 * backend resources (connection pool, timeouts, hashing strategy, …).
 *
 * The applier itself owns no protocol code, so the same implementation drives both
 * the Memcached text-protocol client and the Redis-backed adapter.
 */
final class ClientOptionApplier
{
    public static function apply(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $custom = $env->applyCustomOption($option, $value, $core);
        if ($custom instanceof ClientOptionResult) {
            return $custom;
        }

        if (MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE === $option) {
            self::applyLibketamaCompatible($core, (bool) $value);
            $env->onPoolInvalidated();

            return ClientOptionResult::success();
        }

        if (MemcachedConstants::OPT_DISTRIBUTION === $option) {
            return self::applyDistribution($core, $option, $value, $env);
        }

        if (MemcachedConstants::OPT_HASH === $option) {
            return self::applyHash($core, $option, $value, $env);
        }

        if ($env->isUnsupportedOption($option) || self::isGloballyUnsupported($option)) {
            return ClientOptionResult::failure(MemcachedConstants::RES_NOT_SUPPORTED, $env->unsupportedOptionMessage());
        }

        if (self::isBooleanOption($option)) {
            $core->options[$option] = (bool) $value;
            if (MemcachedConstants::OPT_TCP_KEEPALIVE === $option || MemcachedConstants::OPT_TCP_NODELAY === $option) {
                $env->onPoolInvalidated();
            }

            return ClientOptionResult::success();
        }

        if (MemcachedConstants::OPT_PREFIX_KEY === $option) {
            return self::applyPrefix($core, $option, $value, $env);
        }

        if (MemcachedConstants::OPT_SERIALIZER === $option) {
            return self::applySerializer($core, $option, $value);
        }

        if (MemcachedConstants::OPT_COMPRESSION === $option) {
            $core->options[$option] = (bool) $value;

            return ClientOptionResult::success();
        }

        if (MemcachedConstants::OPT_COMPRESSION_TYPE === $option) {
            return self::applyCompressionType($core, $option, $value);
        }

        if (MemcachedConstants::OPT_COMPRESSION_LEVEL === $option) {
            return self::applyCompressionLevel($core, $option, $value);
        }

        if (MemcachedConstants::OPT_ENCODING_MODE === $option) {
            return self::applyEncodingMode($core, $option, $value);
        }

        if (self::isTimeoutOption($option)) {
            return self::applyTimeout($core, $option, $value, $env);
        }

        if (MemcachedConstants::OPT_USER_FLAGS === $option) {
            return self::applyUserFlags($core, $option, $value);
        }

        if (self::isNonNegativeIntOption($option)) {
            return self::applyNonNegativeInt($core, $option, $value, $env);
        }

        if (self::isMixedStorageOption($option)) {
            $core->options[$option] = $value;

            return ClientOptionResult::success();
        }

        return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
    }

    private static function applyLibketamaCompatible(ClientCoreState $core, bool $enabled): void
    {
        $core->options[MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE] = $enabled;
        $core->selector->setLibketamaCompatible($enabled);
        if ($enabled) {
            $core->selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);
            $core->selector->setHashOption(MemcachedConstants::HASH_MD5);
            $core->options[MemcachedConstants::OPT_DISTRIBUTION] = MemcachedConstants::DISTRIBUTION_CONSISTENT;
            $core->options[MemcachedConstants::OPT_HASH] = MemcachedConstants::HASH_MD5;
        } else {
            $core->selector->setDistribution(MemcachedConstants::DISTRIBUTION_MODULA);
            $core->selector->setHashOption(MemcachedConstants::HASH_DEFAULT);
            $core->options[MemcachedConstants::OPT_DISTRIBUTION] = MemcachedConstants::DISTRIBUTION_MODULA;
            $core->options[MemcachedConstants::OPT_HASH] = MemcachedConstants::HASH_DEFAULT;
        }
    }

    private static function applyDistribution(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $distribution = ClientOptions::intValue($value);
        if (null === $distribution) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $distribution;
        $core->selector->setDistribution($distribution);
        $env->onPoolInvalidated();

        return ClientOptionResult::success();
    }

    private static function applyHash(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $hash = ClientOptions::intValue($value);
        if (null === $hash) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $hash;
        $core->selector->setHashOption($hash);
        $env->onPoolInvalidated();

        return ClientOptionResult::success();
    }

    private static function applyPrefix(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $prefix = ClientOptions::stringValue($value);
        if (null === $prefix) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $strict = $core->optionBool(MemcachedConstants::OPT_VERIFY_KEY, true);
        if ('' !== $prefix && !KeyFormatter::isValid($prefix, $strict, $env->maxKeyLength())) {
            return ClientOptionResult::failure(MemcachedConstants::RES_BAD_KEY_PROVIDED);
        }

        $core->options[$option] = $prefix;

        return ClientOptionResult::success();
    }

    private static function applySerializer(ClientCoreState $core, int $option, mixed $value): ClientOptionResult
    {
        $serializer = ClientOptions::intValue($value);
        if (null === $serializer || !\in_array($serializer, [
            MemcachedConstants::SERIALIZER_PHP,
            MemcachedConstants::SERIALIZER_IGBINARY,
            MemcachedConstants::SERIALIZER_JSON,
            MemcachedConstants::SERIALIZER_JSON_ARRAY,
            MemcachedConstants::SERIALIZER_MSGPACK,
        ], true)) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        if (MemcachedConstants::SERIALIZER_IGBINARY === $serializer && !\extension_loaded('igbinary')) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        if (MemcachedConstants::SERIALIZER_MSGPACK === $serializer && !\extension_loaded('msgpack')) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $serializer;

        return ClientOptionResult::success();
    }

    private static function applyCompressionType(ClientCoreState $core, int $option, mixed $value): ClientOptionResult
    {
        $compressionType = ClientOptions::intValue($value);
        if (null === $compressionType || !\in_array($compressionType, [
            MemcachedConstants::COMPRESSION_TYPE_ZLIB,
            MemcachedConstants::COMPRESSION_TYPE_FASTLZ,
            MemcachedConstants::COMPRESSION_TYPE_ZSTD,
        ], true)) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $compressionType;

        return ClientOptionResult::success();
    }

    private static function applyCompressionLevel(ClientCoreState $core, int $option, mixed $value): ClientOptionResult
    {
        $level = ClientOptions::intValue($value);
        if (null === $level || $level < 0 || $level > 9) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $level;

        return ClientOptionResult::success();
    }

    /**
     * Encryption mode flips the algorithm used by every subsequent
     * {@code setEncodingKey()} call. An already-set encoding context is
     * cleared so the next encoded value uses the new format — keeping
     * leftover state under an incompatible mode would silently corrupt
     * writes. AEAD requires {@code ext-openssl}; libmemcached compat also
     * relies on openssl but is the safer default to keep enabled on hosts
     * that already have the extension available.
     */
    private static function applyEncodingMode(ClientCoreState $core, int $option, mixed $value): ClientOptionResult
    {
        $mode = ClientOptions::intValue($value);
        if (null === $mode || !\in_array($mode, [
            MemcachedConstants::ENCODING_MODE_LIBMEMCACHED,
            MemcachedConstants::ENCODING_MODE_AEAD,
        ], true)) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        if (!\extension_loaded('openssl')) {
            return ClientOptionResult::failure(
                MemcachedConstants::RES_NOT_SUPPORTED,
                'encoding modes require ext-openssl',
            );
        }

        $core->options[$option] = $mode;
        $core->encoding = null;

        return ClientOptionResult::success();
    }

    private static function applyTimeout(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $timeout = ClientOptions::intValue($value);
        if (null === $timeout || $timeout < 0) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $timeout;
        $env->onTimeoutsChanged();

        return ClientOptionResult::success();
    }

    private static function applyUserFlags(ClientCoreState $core, int $option, mixed $value): ClientOptionResult
    {
        $flags = ClientOptions::intValue($value);
        if (null === $flags || $flags < -1 || $flags > 0xFFFF) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $flags;

        return ClientOptionResult::success();
    }

    private static function applyNonNegativeInt(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $integer = ClientOptions::intValue($value);
        if (null === $integer || $integer < 0) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $integer;
        if (MemcachedConstants::OPT_SOCKET_SEND_SIZE === $option || MemcachedConstants::OPT_SOCKET_RECV_SIZE === $option) {
            $env->onPoolInvalidated();
        }

        return ClientOptionResult::success();
    }

    private static function isBooleanOption(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_TCP_NODELAY,
            MemcachedConstants::OPT_TCP_KEEPALIVE,
            MemcachedConstants::OPT_NO_BLOCK,
            MemcachedConstants::OPT_VERIFY_KEY,
            MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY,
            MemcachedConstants::OPT_NOREPLY,
            MemcachedConstants::OPT_BUFFER_WRITES,
            MemcachedConstants::OPT_ALLOW_SERIALIZED_CLASSES,
        ], true);
    }

    private static function isTimeoutOption(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_CONNECT_TIMEOUT,
            MemcachedConstants::OPT_RECV_TIMEOUT,
            MemcachedConstants::OPT_SEND_TIMEOUT,
        ], true);
    }

    private static function isNonNegativeIntOption(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_ITEM_SIZE_LIMIT,
            MemcachedConstants::OPT_SOCKET_SEND_SIZE,
            MemcachedConstants::OPT_SOCKET_RECV_SIZE,
        ], true);
    }

    private static function isMixedStorageOption(int $option): bool
    {
        return MemcachedConstants::OPT_USER_DATA === $option;
    }

    /**
     * Options that no PureCache backend supports today. Each {@see OptionEnvironment} can
     * additionally reject extra protocol-specific options via {@see OptionEnvironment::isUnsupportedOption()}.
     */
    private static function isGloballyUnsupported(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_BINARY_PROTOCOL,
            MemcachedConstants::OPT_USE_UDP,
            MemcachedConstants::OPT_SORT_HOSTS,
            MemcachedConstants::OPT_REMOVE_FAILED_SERVERS,
            MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ,
            MemcachedConstants::OPT_CORK,
            MemcachedConstants::OPT_STORE_RETRY_COUNT,
            MemcachedConstants::OPT_RETRY_TIMEOUT,
            MemcachedConstants::OPT_DEAD_TIMEOUT,
            MemcachedConstants::OPT_POLL_TIMEOUT,
            MemcachedConstants::OPT_SERVER_FAILURE_LIMIT,
            MemcachedConstants::OPT_SERVER_TIMEOUT_LIMIT,
            MemcachedConstants::OPT_NUMBER_OF_REPLICAS,
            MemcachedConstants::OPT_IO_BYTES_WATERMARK,
            MemcachedConstants::OPT_IO_KEY_PREFETCH,
            MemcachedConstants::OPT_IO_MSG_WATERMARK,
            MemcachedConstants::OPT_LOAD_FROM_FILE,
            MemcachedConstants::OPT_SUPPORT_CAS,
            MemcachedConstants::OPT_TCP_KEEPIDLE,
            MemcachedConstants::OPT_LIBKETAMA_HASH,
        ], true);
    }
}
