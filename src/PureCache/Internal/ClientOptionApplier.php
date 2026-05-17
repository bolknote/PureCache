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
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedPropertyTypeCoercion
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

        if (MemcachedConstants::OPT_LIBKETAMA_HASH === $option) {
            return self::applyLibketamaHash($core, $value);
        }

        if (self::isFailoverBooleanOption($option)) {
            return self::applyFailoverBoolean($core, $option, $value, $env);
        }

        if (self::isFailoverNonNegativeIntOption($option)) {
            return self::applyFailoverNonNegativeInt($core, $option, $value, $env);
        }

        if (self::isRedisOnlyOption($option)) {
            return ClientOptionResult::failure(
                MemcachedConstants::RES_NOT_SUPPORTED,
                'TLS options are only supported by the Redis-backed client',
            );
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
        $core->libketamaHashDialTouched = false;
        $core->options[MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE] = $enabled;
        $core->selector->setLibketamaCompatible($enabled);
        if ($enabled) {
            $core->selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);
            $core->selector->setHashOption(MemcachedConstants::HASH_MD5);
            $core->options[MemcachedConstants::OPT_DISTRIBUTION] = MemcachedConstants::DISTRIBUTION_CONSISTENT;
            $core->options[MemcachedConstants::OPT_HASH] = MemcachedConstants::HASH_MD5;
            $core->options[MemcachedConstants::OPT_LIBKETAMA_HASH] = MemcachedConstants::HASH_MD5;
        } else {
            $core->selector->setDistribution(MemcachedConstants::DISTRIBUTION_MODULA);
            $core->selector->setHashOption(MemcachedConstants::HASH_DEFAULT);
            $core->options[MemcachedConstants::OPT_DISTRIBUTION] = MemcachedConstants::DISTRIBUTION_MODULA;
            $core->options[MemcachedConstants::OPT_HASH] = MemcachedConstants::HASH_DEFAULT;
            $core->options[MemcachedConstants::OPT_LIBKETAMA_HASH] = MemcachedConstants::HASH_DEFAULT;
        }
    }

    private static function applyDistribution(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        return self::applySelectorInt($core, $option, $value, $env, static fn (int $v) => $core->selector->setDistribution($v));
    }

    private static function applyHash(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        return self::applySelectorInt($core, $option, $value, $env, static fn (int $v) => $core->selector->setHashOption($v));
    }

    /**
     * Shared spine for option setters that simply forward a coerced integer
     * value into the {@see ServerSelector} and then rebuild the connection
     * pool. {@see applyDistribution()} and {@see applyHash()} differ only in
     * which selector setter they call.
     *
     * @param \Closure(int): void $selectorSetter
     */
    private static function applySelectorInt(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env, \Closure $selectorSetter): ClientOptionResult
    {
        $integer = ClientOptions::intValue($value);
        if (null === $integer) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        if (MemcachedConstants::OPT_HASH === $option) {
            // PECL mirrors OPT_HASH into the ketama getter on hash changes until
            // setOption(OPT_LIBKETAMA_HASH) touches the dial (LibketamaHashOptionParity).
            $core->libketamaHashDialTouched = false;
            $core->options[MemcachedConstants::OPT_HASH] = $integer;
            $core->options[MemcachedConstants::OPT_LIBKETAMA_HASH] = $integer;

            $selectorSetter($integer);
            $env->onPoolInvalidated();

            return ClientOptionResult::success();
        }

        $core->options[$option] = $integer;

        $selectorSetter($integer);
        $env->onPoolInvalidated();

        return ClientOptionResult::success();
    }

    /**
     * PECL routes {@code OPT_LIBKETAMA_HASH} through the generic
     * {@code default:} arm of {@code php_memc_set_option()}, which:
     *  1. coerces the user-supplied value with {@code zval_get_long()} —
     *     booleans, nulls, numeric/non-numeric strings, floats, arrays and
     *     objects all flow through PHP's standard int widening rules
     *     instead of being rejected;
     *  2. forwards the coerced long to libmemcached's
     *     {@code MEMCACHED_BEHAVIOR_KETAMA_HASH}, whose hashkit backend
     *     accepts every documented {@code HASH_*} id and silently accepts
     *     out-of-range values like 9999 — except {@code HASH_HSIEH}, which
     *     PECL builds without ({@code HAVE_HSIEH_HASH=disabled}) and the
     *     hashkit setter therefore rejects with {@code INVALID_ARGUMENT}.
     *
     * Empirically the dial does not move keys around (routing is driven by
     * {@code OPT_HASH}). On ext-memcached 3.4.x the getter keeps tracking
     * {@code OPT_HASH}; on older builds it surfaces the coerced ketama value.
     * PureCache mirrors the loaded extension via
     * {@see LibketamaHashOptionParity::setterUpdatesStoredKetamaGetter()}.
     */
    private static function applyLibketamaHash(ClientCoreState $core, mixed $value): ClientOptionResult
    {
        $hash = ClientOptions::peclLongValue($value);

        if (MemcachedConstants::HASH_HSIEH === $hash) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->libketamaHashDialTouched = true;
        $core->options[MemcachedConstants::OPT_LIBKETAMA_HASH] = $hash;

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

        if (!ClientOptions::serializerIsUsable($serializer)) {
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
        if (\in_array($option, [
            MemcachedConstants::OPT_ITEM_SIZE_LIMIT,
            MemcachedConstants::OPT_SOCKET_SEND_SIZE,
            MemcachedConstants::OPT_SOCKET_RECV_SIZE,
            // TCP_KEEPIDLE is applied at socket-open time inside
            // {@see \PureCache\Memcached\Internal\StreamConnection}, so the
            // pool has to be torn down for the new value to take effect on
            // any future read/write — same model as OPT_SOCKET_SEND_SIZE.
            MemcachedConstants::OPT_TCP_KEEPIDLE,
        ], true)) {
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
            // Stored for getOption parity only; the meta protocol always
            // returns CAS regardless of this flag (see applier preamble note).
            MemcachedConstants::OPT_SUPPORT_CAS,
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
            MemcachedConstants::OPT_TCP_KEEPIDLE,
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
        ], true);
    }

    /**
     * Boolean libmemcached failover/tuning toggles wired into shared client state.
     *
     * - {@code OPT_SORT_HOSTS} — selector resorts the server list lexicographically
     *   and invalidates the ketama continuum so the next pick re-hashes.
     * - {@code OPT_REMOVE_FAILED_SERVERS} — failure tracker switches between
     *   "route around" (true) and "mark dead but keep in ring" (false).
     * - {@code OPT_RANDOMIZE_REPLICA_READ} — consumed at read time by
     *   {@see ServerSelector::pickReadIndex()}; setter just records the value.
     * - {@code OPT_CORK} — memcached transport applies {@code TCP_CORK} on
     *   Linux through {@see OptionEnvironment::onPoolInvalidated()} so the
     *   socket is rebuilt with the new flag. No-op on macOS/BSD (matches
     *   libmemcached's documented behaviour).
     */
    private static function isFailoverBooleanOption(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_SORT_HOSTS,
            MemcachedConstants::OPT_REMOVE_FAILED_SERVERS,
            MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ,
            MemcachedConstants::OPT_CORK,
        ], true);
    }

    private static function applyFailoverBoolean(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $enabled = (bool) $value;
        $previous = $core->optionBool($option, false);
        $core->options[$option] = $enabled;

        if (MemcachedConstants::OPT_SORT_HOSTS === $option) {
            $core->selector->setSortHosts($enabled);
            if ($previous !== $enabled) {
                $env->onPoolInvalidated();
            }

            return ClientOptionResult::success();
        }

        if (MemcachedConstants::OPT_REMOVE_FAILED_SERVERS === $option) {
            $core->failureTracker->setRemoveFailed($enabled);

            return ClientOptionResult::success();
        }

        if (MemcachedConstants::OPT_CORK === $option) {
            if ($previous !== $enabled) {
                // Route through onTimeoutsChanged() — the memcached transport
                // only reads OPT_CORK in the ConnectionManager constructor,
                // so a plain pool invalidation (closeAll()) would leave the
                // old cork flag on the manager itself.
                $env->onTimeoutsChanged();
            }

            return ClientOptionResult::success();
        }

        return ClientOptionResult::success();
    }

    /**
     * Non-negative integer failover/tuning options pushed into the shared
     * tracker, selector, and transport surfaces. Generic non-negative ints
     * still live in {@see applyNonNegativeInt()} — these branches additionally
     * mirror the value into a tracker/transport field so subsequent requests
     * see the new value without going through PECL's full reconfigure dance.
     */
    private static function isFailoverNonNegativeIntOption(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_SERVER_FAILURE_LIMIT,
            MemcachedConstants::OPT_SERVER_TIMEOUT_LIMIT,
            MemcachedConstants::OPT_NUMBER_OF_REPLICAS,
            MemcachedConstants::OPT_STORE_RETRY_COUNT,
            MemcachedConstants::OPT_RETRY_TIMEOUT,
            MemcachedConstants::OPT_DEAD_TIMEOUT,
            MemcachedConstants::OPT_POLL_TIMEOUT,
            MemcachedConstants::OPT_IO_BYTES_WATERMARK,
            MemcachedConstants::OPT_IO_MSG_WATERMARK,
            MemcachedConstants::OPT_IO_KEY_PREFETCH,
        ], true);
    }

    private static function applyFailoverNonNegativeInt(ClientCoreState $core, int $option, mixed $value, OptionEnvironment $env): ClientOptionResult
    {
        $integer = ClientOptions::intValue($value);
        if (null === $integer || $integer < 0) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS);
        }

        $core->options[$option] = $integer;

        switch ($option) {
            case MemcachedConstants::OPT_SERVER_FAILURE_LIMIT:
                $core->failureTracker->setFailureLimit($integer);
                break;
            case MemcachedConstants::OPT_SERVER_TIMEOUT_LIMIT:
                $core->failureTracker->setTimeoutLimit($integer);
                break;
            case MemcachedConstants::OPT_RETRY_TIMEOUT:
                $core->failureTracker->setRetryTimeoutSec($integer);
                break;
            case MemcachedConstants::OPT_DEAD_TIMEOUT:
                $core->failureTracker->setDeadTimeoutSec($integer);
                break;
            case MemcachedConstants::OPT_POLL_TIMEOUT:
            case MemcachedConstants::OPT_IO_BYTES_WATERMARK:
            case MemcachedConstants::OPT_IO_MSG_WATERMARK:
            case MemcachedConstants::OPT_IO_KEY_PREFETCH:
                $env->onTimeoutsChanged();
                break;
            case MemcachedConstants::OPT_NUMBER_OF_REPLICAS:
            case MemcachedConstants::OPT_STORE_RETRY_COUNT:
                // Stored in $core->options; consumed by AbstractCacheClient
                // helpers at request time. No transport rebuild required.
                break;
        }

        return ClientOptionResult::success();
    }

    private static function isRedisOnlyOption(int $option): bool
    {
        return MemcachedConstants::OPT_TLS_CA_FILE === $option
            || MemcachedConstants::OPT_TLS_PEER_NAME === $option;
    }
}
