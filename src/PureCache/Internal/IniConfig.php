<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Reads every {@code memcached.*} php.ini directive PECL registers in
 * {@code php_memcached.c} and exposes them as typed values.
 *
 * Mirrors the PECL behavior 1:1:
 *  - default values are copied from {@code PHP_INI_BEGIN()} entries (NOT from
 *    {@code php_memc_init_globals()} where the registered INI default wins
 *    against the C-level pre-init);
 *  - {@code OnUpdate*} validators are reproduced — invalid strings raise a
 *    {@code trigger_error(E_WARNING)} with the same wording as PECL and fall
 *    back to the documented default (PECL's behavior is to reject the INI
 *    update and keep the previous value; for first-read this is the default).
 *
 * Directives this class does **not** model:
 *  - {@code memcached.use_sasl} — removed in php-memcached 3.0.
 *  - Deprecated aliases {@code memcached.sess_lock_wait},
 *    {@code memcached.sess_lock_max_wait} — PECL keeps them as no-ops and
 *    emits a deprecation warning when set; if a host has them in php.ini we
 *    warn here as well via {@see snapshotSession()}.
 *  - {@code memcached.sess_binary} — replaced by
 *    {@code memcached.sess_binary_protocol}; {@see snapshotSession()} maps it
 *    transparently when the new key is absent.
 *
 * Tests inject a custom reader to side-step PHP's "directive must be
 * registered" restriction on {@code ini_set()}; production code uses the
 * default reader backed by {@see ini_get()}.
 */
final class IniConfig
{
    public const string COMPRESSION_TYPE_DEFAULT = 'fastlz';

    public const int COMPRESSION_THRESHOLD_DEFAULT = 2000;

    public const float COMPRESSION_FACTOR_DEFAULT = 1.3;

    public const int COMPRESSION_LEVEL_DEFAULT = 3;

    public const int STORE_RETRY_COUNT_DEFAULT = 0;

    public const int ITEM_SIZE_LIMIT_DEFAULT = 0;

    public const string SESS_PREFIX_DEFAULT = 'memc.sess.key.';

    public const int SESS_LOCK_WAIT_MIN_DEFAULT = 150;

    public const int SESS_LOCK_WAIT_MAX_DEFAULT = 150;

    public const int SESS_LOCK_RETRIES_DEFAULT = 5;

    public const int SESS_LOCK_EXPIRE_DEFAULT = 0;

    public const int SESS_CONNECT_TIMEOUT_DEFAULT = 0;

    public const string SESS_CONSISTENT_HASH_TYPE_DEFAULT = 'ketama';

    public const int SESS_NUMBER_OF_REPLICAS_DEFAULT = 0;

    public const int SESS_SERVER_FAILURE_LIMIT_DEFAULT = 0;

    public const string CONSISTENT_HASH_KETAMA = 'ketama';

    public const string CONSISTENT_HASH_KETAMA_WEIGHTED = 'ketama_weighted';

    private function __construct()
    {
    }

    /**
     * @param (\Closure(string): ?string)|null $reader
     *
     * @return array{
     *   serializer:int,
     *   compression_type:int,
     *   compression_level:int,
     *   compression_threshold:int,
     *   compression_factor:float,
     *   store_retry_count:int,
     *   item_size_limit:int,
     *   default_consistent_hash:bool,
     *   default_binary_protocol:bool,
     *   default_connect_timeout:int,
     * }
     */
    public static function snapshot(?\Closure $reader = null): array
    {
        $reader ??= self::defaultReader();

        return [
            'serializer' => self::compressionTypeOrSerializer($reader, 'memcached.serializer', self::serializerMap(), ClientOptions::defaultSerializer(), 'memcached.serializer must be php, igbinary, json, json_array or msgpack, "%s" given'),
            'compression_type' => self::compressionTypeOrSerializer($reader, 'memcached.compression_type', self::compressionTypeMap(), MemcachedConstants::COMPRESSION_TYPE_FASTLZ, 'memcached.compression_type must be fastlz, zlib or zstd, "%s" given'),
            'compression_level' => self::longIni($reader, 'memcached.compression_level', self::COMPRESSION_LEVEL_DEFAULT),
            'compression_threshold' => self::longIni($reader, 'memcached.compression_threshold', self::COMPRESSION_THRESHOLD_DEFAULT),
            'compression_factor' => self::floatIni($reader, 'memcached.compression_factor', self::COMPRESSION_FACTOR_DEFAULT),
            'store_retry_count' => self::longIni($reader, 'memcached.store_retry_count', self::STORE_RETRY_COUNT_DEFAULT),
            'item_size_limit' => self::longGEZeroIni($reader, 'memcached.item_size_limit', self::ITEM_SIZE_LIMIT_DEFAULT),
            'default_consistent_hash' => self::boolIni($reader, 'memcached.default_consistent_hash', false),
            'default_binary_protocol' => self::boolIni($reader, 'memcached.default_binary_protocol', false),
            'default_connect_timeout' => self::longIni($reader, 'memcached.default_connect_timeout', 0),
        ];
    }

    /**
     * @param (\Closure(string): ?string)|null $reader
     *
     * @return array{
     *   lock_enabled:bool,
     *   lock_wait_min:int,
     *   lock_wait_max:int,
     *   lock_retries:int,
     *   lock_expiration:int,
     *   binary_protocol_enabled:bool,
     *   consistent_hash_enabled:bool,
     *   consistent_hash_type:string,
     *   number_of_replicas:int,
     *   randomize_replica_read_enabled:bool,
     *   remove_failed_servers_enabled:bool,
     *   server_failure_limit:int,
     *   connect_timeout:int,
     *   sasl_username:?string,
     *   sasl_password:?string,
     *   persistent_enabled:bool,
     *   prefix:string,
     * }
     */
    public static function snapshotSession(?\Closure $reader = null): array
    {
        $reader ??= self::defaultReader();

        $binary = self::boolIniOptional($reader, 'memcached.sess_binary_protocol');
        if (null === $binary) {
            $binary = self::boolIniOptional($reader, 'memcached.sess_binary');
        }

        if (null === $binary) {
            $binary = self::sessionBinaryProtocolDefault();
        }

        self::warnDeprecated($reader, 'memcached.sess_lock_wait');
        self::warnDeprecated($reader, 'memcached.sess_lock_max_wait');

        return [
            'lock_enabled' => self::boolIni($reader, 'memcached.sess_locking', true),
            'lock_wait_min' => self::longGEZeroIni($reader, 'memcached.sess_lock_wait_min', self::SESS_LOCK_WAIT_MIN_DEFAULT),
            'lock_wait_max' => self::longGEZeroIni($reader, 'memcached.sess_lock_wait_max', self::SESS_LOCK_WAIT_MAX_DEFAULT),
            'lock_retries' => self::longIni($reader, 'memcached.sess_lock_retries', self::SESS_LOCK_RETRIES_DEFAULT),
            'lock_expiration' => self::longGEZeroIni($reader, 'memcached.sess_lock_expire', self::SESS_LOCK_EXPIRE_DEFAULT),
            'binary_protocol_enabled' => $binary,
            'consistent_hash_enabled' => self::boolIni($reader, 'memcached.sess_consistent_hash', true),
            'consistent_hash_type' => self::consistentHashType($reader),
            'number_of_replicas' => self::longGEZeroIni($reader, 'memcached.sess_number_of_replicas', self::SESS_NUMBER_OF_REPLICAS_DEFAULT),
            'randomize_replica_read_enabled' => self::boolIni($reader, 'memcached.sess_randomize_replica_read', false),
            'remove_failed_servers_enabled' => self::boolIni($reader, 'memcached.sess_remove_failed_servers', false),
            'server_failure_limit' => self::longGEZeroIni($reader, 'memcached.sess_server_failure_limit', self::SESS_SERVER_FAILURE_LIMIT_DEFAULT),
            'connect_timeout' => self::longIni($reader, 'memcached.sess_connect_timeout', self::SESS_CONNECT_TIMEOUT_DEFAULT),
            'sasl_username' => self::stringOrNull(self::stringIni($reader, 'memcached.sess_sasl_username', '')),
            'sasl_password' => self::stringOrNull(self::stringIni($reader, 'memcached.sess_sasl_password', '')),
            'persistent_enabled' => self::boolIni($reader, 'memcached.sess_persistent', false),
            'prefix' => self::sessionPrefix($reader),
        ];
    }

    /**
     * Returns the same closure {@see snapshot()} uses when none is provided.
     *
     * @return \Closure(string): ?string
     */
    public static function defaultReader(): \Closure
    {
        return static function (string $key): ?string {
            $value = \ini_get($key);
            if (false === $value) {
                return null;
            }

            return $value;
        };
    }

    /**
     * @return array<string, int>
     */
    private static function serializerMap(): array
    {
        return [
            'php' => MemcachedConstants::SERIALIZER_PHP,
            'igbinary' => MemcachedConstants::SERIALIZER_IGBINARY,
            'json' => MemcachedConstants::SERIALIZER_JSON,
            'json_array' => MemcachedConstants::SERIALIZER_JSON_ARRAY,
            'msgpack' => MemcachedConstants::SERIALIZER_MSGPACK,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function compressionTypeMap(): array
    {
        return [
            'fastlz' => MemcachedConstants::COMPRESSION_TYPE_FASTLZ,
            'zlib' => MemcachedConstants::COMPRESSION_TYPE_ZLIB,
            'zstd' => MemcachedConstants::COMPRESSION_TYPE_ZSTD,
        ];
    }

    /**
     * Shared implementation of {@code OnUpdateCompressionType} and
     * {@code OnUpdateSerializer}: empty value → default, unknown value → warn + default.
     *
     * @param \Closure(string): ?string $reader
     * @param array<string, int>        $map
     */
    private static function compressionTypeOrSerializer(\Closure $reader, string $key, array $map, int $default, string $errorTemplate): int
    {
        $value = $reader($key);
        if (null === $value || '' === $value) {
            return $default;
        }

        if (isset($map[$value])) {
            return $map[$value];
        }

        trigger_error(\sprintf($errorTemplate, $value), \E_USER_WARNING);

        return $default;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    public static function consistentHashType(\Closure $reader): string
    {
        $value = $reader('memcached.sess_consistent_hash_type');
        if (null === $value || '' === $value) {
            return self::CONSISTENT_HASH_KETAMA;
        }

        if (self::CONSISTENT_HASH_KETAMA === $value || self::CONSISTENT_HASH_KETAMA_WEIGHTED === $value) {
            return $value;
        }

        trigger_error(
            \sprintf('memcached.sess_consistent_hash_type must be ketama or ketama_weighted, "%s" given', $value),
            \E_USER_WARNING,
        );

        return self::CONSISTENT_HASH_KETAMA;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function sessionPrefix(\Closure $reader): string
    {
        $value = $reader('memcached.sess_prefix');
        if (null === $value) {
            return self::SESS_PREFIX_DEFAULT;
        }

        if ('' !== $value && \strlen($value) > 218) {
            trigger_error('memcached.sess_prefix too long (max: 218)', \E_USER_WARNING);

            return self::SESS_PREFIX_DEFAULT;
        }

        return $value;
    }

    private static function sessionBinaryProtocolDefault(): bool
    {
        // PECL: "1" with libmemcached >= 1.0.18, "0" otherwise. We always run
        // over our own transport so we treat ourselves as "modern" and default
        // to On to match the canonical 3.x default. The actual on-wire
        // protocol stays meta — the warning lands when the directive is read
        // by the session handler, not here.
        return true;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function warnDeprecated(\Closure $reader, string $key): void
    {
        $raw = $reader($key);
        if (null !== $raw && '' !== $raw && 'not set' !== $raw) {
            @trigger_error(
                \sprintf('%s is deprecated and has no effect', $key),
                \E_USER_DEPRECATED,
            );
        }
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function stringIni(\Closure $reader, string $key, string $default): string
    {
        $value = $reader($key);

        return $value ?? $default;
    }

    private static function stringOrNull(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function boolIni(\Closure $reader, string $key, bool $default): bool
    {
        $value = self::boolIniOptional($reader, $key);

        return $value ?? $default;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function boolIniOptional(\Closure $reader, string $key): ?bool
    {
        $raw = $reader($key);
        if (null === $raw || '' === $raw) {
            return null;
        }

        // Mirrors zend_ini_parse_bool(): "1", "on", "yes", "true" (case-insensitive)
        // map to true; "0", "off", "no", "false", "" map to false.
        $filtered = filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null !== $filtered) {
            return $filtered;
        }

        return (bool) (int) $raw;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function longIni(\Closure $reader, string $key, int $default): int
    {
        $raw = $reader($key);
        if (null === $raw || '' === $raw) {
            return $default;
        }

        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        if (1 === preg_match('/^-\d+$/', $raw)) {
            return (int) $raw;
        }

        return (int) $raw;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function longGEZeroIni(\Closure $reader, string $key, int $default): int
    {
        $value = self::longIni($reader, $key, $default);
        if ($value < 0) {
            trigger_error(
                \sprintf('%s must be greater than or equal to zero, %d given', $key, $value),
                \E_USER_WARNING,
            );

            return $default;
        }

        return $value;
    }

    /**
     * @param \Closure(string): ?string $reader
     */
    private static function floatIni(\Closure $reader, string $key, float $default): float
    {
        $raw = $reader($key);
        if (null === $raw || '' === $raw) {
            return $default;
        }

        return (float) $raw;
    }
}
