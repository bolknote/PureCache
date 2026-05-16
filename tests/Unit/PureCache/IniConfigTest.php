<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientOptions;
use PureCache\Internal\IniConfig;
use PureCache\MemcachedConstants;

/**
 * Validates that {@see IniConfig} reproduces PECL's {@code php_memcached.c}
 * defaults and {@code OnUpdate*} validator semantics. Tests inject a fake
 * reader because PHP's {@code ini_set()} refuses unknown directives like
 * {@code memcached.serializer} unless the PECL extension is loaded, so the
 * standard {@code ini_get}/{@code ini_set} pair cannot drive these tests.
 */
final class IniConfigTest extends TestCase
{
    public function testSnapshotDefaultsWhenNoIniDirectivesAreSet(): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom([]));

        self::assertSame(ClientOptions::defaultSerializer(), $snapshot['serializer']);
        self::assertSame(MemcachedConstants::COMPRESSION_TYPE_FASTLZ, $snapshot['compression_type']);
        self::assertSame(IniConfig::COMPRESSION_LEVEL_DEFAULT, $snapshot['compression_level']);
        self::assertSame(IniConfig::COMPRESSION_THRESHOLD_DEFAULT, $snapshot['compression_threshold']);
        self::assertSame(IniConfig::COMPRESSION_FACTOR_DEFAULT, $snapshot['compression_factor']);
        self::assertSame(IniConfig::STORE_RETRY_COUNT_DEFAULT, $snapshot['store_retry_count']);
        self::assertSame(IniConfig::ITEM_SIZE_LIMIT_DEFAULT, $snapshot['item_size_limit']);
        self::assertFalse($snapshot['default_consistent_hash']);
        self::assertFalse($snapshot['default_binary_protocol']);
        self::assertSame(0, $snapshot['default_connect_timeout']);
    }

    #[DataProvider('compressionTypeProvider')]
    public function testCompressionTypeAcceptsPeclValues(string $iniValue, int $expected): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom(['memcached.compression_type' => $iniValue]));

        self::assertSame($expected, $snapshot['compression_type']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function compressionTypeProvider(): iterable
    {
        yield 'fastlz' => ['fastlz', MemcachedConstants::COMPRESSION_TYPE_FASTLZ];
        yield 'zlib' => ['zlib', MemcachedConstants::COMPRESSION_TYPE_ZLIB];
        yield 'zstd' => ['zstd', MemcachedConstants::COMPRESSION_TYPE_ZSTD];
    }

    public function testCompressionTypeWarnsOnUnknownValueAndFallsBackToFastlz(): void
    {
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshot($this->readerFrom(['memcached.compression_type' => 'snappy'])),
            $snapshot,
        );

        self::assertSame(MemcachedConstants::COMPRESSION_TYPE_FASTLZ, $snapshot['compression_type']);
        self::assertStringContainsString('memcached.compression_type must be fastlz, zlib or zstd', (string) $error);
        self::assertStringContainsString('"snappy"', (string) $error);
    }

    #[DataProvider('serializerProvider')]
    public function testSerializerAcceptsPeclValues(string $iniValue, int $expected): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom(['memcached.serializer' => $iniValue]));

        self::assertSame($expected, $snapshot['serializer']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function serializerProvider(): iterable
    {
        yield 'php' => ['php', MemcachedConstants::SERIALIZER_PHP];
        yield 'igbinary' => ['igbinary', MemcachedConstants::SERIALIZER_IGBINARY];
        yield 'json' => ['json', MemcachedConstants::SERIALIZER_JSON];
        yield 'json_array' => ['json_array', MemcachedConstants::SERIALIZER_JSON_ARRAY];
        yield 'msgpack' => ['msgpack', MemcachedConstants::SERIALIZER_MSGPACK];
    }

    public function testSerializerWarnsOnUnknownValueAndFallsBackToDefault(): void
    {
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshot($this->readerFrom(['memcached.serializer' => 'protobuf'])),
            $snapshot,
        );

        self::assertSame(ClientOptions::defaultSerializer(), $snapshot['serializer']);
        self::assertStringContainsString('memcached.serializer must be php, igbinary, json, json_array or msgpack', (string) $error);
    }

    public function testSnapshotReadsAllNonSessionDirectives(): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom([
            'memcached.compression_type' => 'zlib',
            'memcached.compression_level' => '7',
            'memcached.compression_threshold' => '512',
            'memcached.compression_factor' => '1.7',
            'memcached.serializer' => 'php',
            'memcached.store_retry_count' => '4',
            'memcached.item_size_limit' => '1024',
            'memcached.default_consistent_hash' => 'On',
            'memcached.default_binary_protocol' => 'Off',
            'memcached.default_connect_timeout' => '500',
        ]));

        self::assertSame(MemcachedConstants::COMPRESSION_TYPE_ZLIB, $snapshot['compression_type']);
        self::assertSame(7, $snapshot['compression_level']);
        self::assertSame(512, $snapshot['compression_threshold']);
        self::assertSame(1.7, $snapshot['compression_factor']);
        self::assertSame(MemcachedConstants::SERIALIZER_PHP, $snapshot['serializer']);
        self::assertSame(4, $snapshot['store_retry_count']);
        self::assertSame(1024, $snapshot['item_size_limit']);
        self::assertTrue($snapshot['default_consistent_hash']);
        self::assertFalse($snapshot['default_binary_protocol']);
        self::assertSame(500, $snapshot['default_connect_timeout']);
    }

    public function testNegativeItemSizeLimitTriggersWarningAndFallsBackToDefault(): void
    {
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshot($this->readerFrom(['memcached.item_size_limit' => '-42'])),
            $snapshot,
        );

        self::assertSame(IniConfig::ITEM_SIZE_LIMIT_DEFAULT, $snapshot['item_size_limit']);
        self::assertStringContainsString('memcached.item_size_limit must be greater than or equal to zero', (string) $error);
    }

    public function testSessionSnapshotDefaultsMirrorPeclMemcachedIniRegistration(): void
    {
        $snapshot = IniConfig::snapshotSession($this->readerFrom([]));

        self::assertTrue($snapshot['lock_enabled']);
        self::assertSame(IniConfig::SESS_LOCK_WAIT_MIN_DEFAULT, $snapshot['lock_wait_min']);
        self::assertSame(IniConfig::SESS_LOCK_WAIT_MAX_DEFAULT, $snapshot['lock_wait_max']);
        self::assertSame(IniConfig::SESS_LOCK_RETRIES_DEFAULT, $snapshot['lock_retries']);
        self::assertSame(IniConfig::SESS_LOCK_EXPIRE_DEFAULT, $snapshot['lock_expiration']);
        self::assertTrue($snapshot['binary_protocol_enabled']);
        self::assertTrue($snapshot['consistent_hash_enabled']);
        self::assertSame(IniConfig::SESS_CONSISTENT_HASH_TYPE_DEFAULT, $snapshot['consistent_hash_type']);
        self::assertSame(IniConfig::SESS_NUMBER_OF_REPLICAS_DEFAULT, $snapshot['number_of_replicas']);
        self::assertFalse($snapshot['randomize_replica_read_enabled']);
        self::assertFalse($snapshot['remove_failed_servers_enabled']);
        self::assertSame(IniConfig::SESS_SERVER_FAILURE_LIMIT_DEFAULT, $snapshot['server_failure_limit']);
        self::assertSame(IniConfig::SESS_CONNECT_TIMEOUT_DEFAULT, $snapshot['connect_timeout']);
        self::assertNull($snapshot['sasl_username']);
        self::assertNull($snapshot['sasl_password']);
        self::assertFalse($snapshot['persistent_enabled']);
        self::assertSame(IniConfig::SESS_PREFIX_DEFAULT, $snapshot['prefix']);
    }

    public function testSessionPrefixOverflowTriggersWarningAndFallsBackToDefault(): void
    {
        $prefix = str_repeat('a', 219);
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshotSession($this->readerFrom(['memcached.sess_prefix' => $prefix])),
            $snapshot,
        );

        self::assertSame(IniConfig::SESS_PREFIX_DEFAULT, $snapshot['prefix']);
        self::assertStringContainsString('memcached.sess_prefix too long', (string) $error);
    }

    #[DataProvider('consistentHashTypeProvider')]
    public function testSessionConsistentHashTypeAcceptsPeclValues(string $iniValue, string $expected): void
    {
        $snapshot = IniConfig::snapshotSession($this->readerFrom(['memcached.sess_consistent_hash_type' => $iniValue]));

        self::assertSame($expected, $snapshot['consistent_hash_type']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function consistentHashTypeProvider(): iterable
    {
        yield 'ketama' => ['ketama', IniConfig::CONSISTENT_HASH_KETAMA];
        yield 'ketama_weighted' => ['ketama_weighted', IniConfig::CONSISTENT_HASH_KETAMA_WEIGHTED];
    }

    public function testSessionConsistentHashTypeRejectsUnknownValue(): void
    {
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshotSession($this->readerFrom(['memcached.sess_consistent_hash_type' => 'rendezvous'])),
            $snapshot,
        );

        self::assertSame(IniConfig::CONSISTENT_HASH_KETAMA, $snapshot['consistent_hash_type']);
        self::assertStringContainsString('memcached.sess_consistent_hash_type must be ketama or ketama_weighted', (string) $error);
    }

    public function testSessionBinaryFallsBackToDeprecatedAliasWhenNewKeyIsUnset(): void
    {
        $snapshot = IniConfig::snapshotSession($this->readerFrom(['memcached.sess_binary' => '0']));

        self::assertFalse($snapshot['binary_protocol_enabled']);
    }

    public function testDeprecatedLockWaitAliasEmitsDeprecation(): void
    {
        $deprecated = '';
        set_error_handler(static function (int $severity, string $message) use (&$deprecated): bool {
            if (\E_USER_DEPRECATED === $severity) {
                $deprecated = $message;
            }

            return true;
        });

        try {
            IniConfig::snapshotSession($this->readerFrom(['memcached.sess_lock_wait' => '5000']));
        } finally {
            restore_error_handler();
        }

        self::assertStringContainsString('memcached.sess_lock_wait is deprecated', $deprecated);
    }

    public function testDefaultReaderReturnsNullWhenIniGetFails(): void
    {
        $reader = IniConfig::defaultReader();

        self::assertNull($reader('memcached.__purecache_nonexistent__'));
    }

    public function testSessionPrefixAcceptsCustomValueWithinLimit(): void
    {
        $snapshot = IniConfig::snapshotSession($this->readerFrom([
            'memcached.sess_prefix' => 'custom.sess.',
        ]));

        self::assertSame('custom.sess.', $snapshot['prefix']);
    }

    public function testSessionPrefixRejectsOverlongValue(): void
    {
        $error = $this->captureWarning(
            fn (): array => IniConfig::snapshotSession($this->readerFrom([
                'memcached.sess_prefix' => str_repeat('p', 219),
            ])),
            $snapshot,
        );

        self::assertSame(IniConfig::SESS_PREFIX_DEFAULT, $snapshot['prefix']);
        self::assertStringContainsString('memcached.sess_prefix too long', (string) $error);
    }

    public function testBoolIniCoercesNumericStringsWhenFilterVarFails(): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom(['memcached.default_consistent_hash' => '2']));

        self::assertTrue($snapshot['default_consistent_hash']);
    }

    public function testLongIniCoercesNonDigitStringsToInteger(): void
    {
        $snapshot = IniConfig::snapshot($this->readerFrom(['memcached.compression_threshold' => '12abc']));

        self::assertSame(12, $snapshot['compression_threshold']);
    }

    public function testSessionSaslCredentialsRoundTripThroughSnapshot(): void
    {
        $snapshot = IniConfig::snapshotSession($this->readerFrom([
            'memcached.sess_sasl_username' => 'user',
            'memcached.sess_sasl_password' => 'pass',
        ]));

        self::assertSame('user', $snapshot['sasl_username']);
        self::assertSame('pass', $snapshot['sasl_password']);
    }

    /**
     * @param array<string, string> $entries
     *
     * @return \Closure(string): ?string
     */
    private function readerFrom(array $entries): \Closure
    {
        return static fn (string $key): ?string => $entries[$key] ?? null;
    }

    /**
     * Runs the snapshot closure, captures the first {@code E_USER_WARNING}
     * triggered during the call, and writes the snapshot into the by-reference
     * argument. Mirrors how PECL surfaces validator errors as PHP warnings.
     *
     * @template TSnapshot of array<string, mixed>
     *
     * @param \Closure():TSnapshot $body
     *
     * @param-out TSnapshot        $snapshot
     */
    private function captureWarning(\Closure $body, mixed &$snapshot): ?string
    {
        $captured = null;
        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            if (\E_USER_WARNING === $severity && null === $captured) {
                $captured = $message;
            }

            return true;
        });

        try {
            $snapshot = $body();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
