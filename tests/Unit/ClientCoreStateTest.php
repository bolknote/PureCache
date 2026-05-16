<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptions;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

/**
 * Covers the {@code memcached.*} INI-snapshot fan-out implemented by
 * {@see ClientCoreState::applyIniDefaults()} plus the typed
 * {@see ClientCoreState::optionInt()}/{@see ClientCoreState::optionBool()}
 * accessors.
 *
 * The class is abstract; tests use {@see MemcachedClientCore} as the concrete
 * subclass since its construction is side-effect-free (no socket is opened
 * until a request actually runs).
 */
final class ClientCoreStateTest extends TestCase
{
    public function testApplyIniDefaultsCopiesSnapshotIntoOptionsAndCompressionFields(): void
    {
        $core = MemcachedClientCore::createFresh();

        $snapshot = $this->snapshot([
            'serializer' => MemcachedConstants::SERIALIZER_JSON,
            'compression_type' => MemcachedConstants::COMPRESSION_TYPE_ZSTD,
            'compression_level' => 7,
            'compression_threshold' => 4096,
            'compression_factor' => 1.42,
            'store_retry_count' => 5,
            'item_size_limit' => 1_048_576,
        ]);

        $core->applyIniDefaults($snapshot);

        self::assertSame(MemcachedConstants::SERIALIZER_JSON, $core->options[MemcachedConstants::OPT_SERIALIZER]);
        self::assertSame(MemcachedConstants::COMPRESSION_TYPE_ZSTD, $core->options[MemcachedConstants::OPT_COMPRESSION_TYPE]);
        self::assertSame(7, $core->options[MemcachedConstants::OPT_COMPRESSION_LEVEL]);
        self::assertSame(5, $core->options[MemcachedConstants::OPT_STORE_RETRY_COUNT]);
        self::assertSame(1_048_576, $core->options[MemcachedConstants::OPT_ITEM_SIZE_LIMIT]);
        self::assertSame(4096, $core->compressionThreshold);
        self::assertSame(1.42, $core->compressionFactor);
    }

    public function testApplyIniDefaultsHonorsPhpIniSerializerEvenWhenCompileDefaultDiffers(): void
    {
        $compileDefault = ClientOptions::defaultSerializer();
        if (MemcachedConstants::SERIALIZER_PHP === $compileDefault) {
            self::markTestSkipped('compile-time default serializer is already PHP');
        }

        $core = MemcachedClientCore::createFresh();
        $core->applyIniDefaults($this->snapshot(['serializer' => MemcachedConstants::SERIALIZER_PHP]));

        self::assertSame(MemcachedConstants::SERIALIZER_PHP, $core->options[MemcachedConstants::OPT_SERIALIZER]);
    }

    public function testApplyIniDefaultsWithConsistentHashTurnsOnKetamaDistribution(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->applyIniDefaults($this->snapshot(['default_consistent_hash' => true]));

        self::assertSame(
            MemcachedConstants::DISTRIBUTION_CONSISTENT,
            $core->options[MemcachedConstants::OPT_DISTRIBUTION],
        );
        $selector = new \ReflectionProperty($core->selector, 'distribution');
        self::assertSame(MemcachedConstants::DISTRIBUTION_CONSISTENT, $selector->getValue($core->selector));
    }

    public function testApplyIniDefaultsWithBinaryProtocolWarnsAndStoresFlag(): void
    {
        $core = MemcachedClientCore::createFresh();

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if (\E_USER_WARNING === $errno) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            $core->applyIniDefaults($this->snapshot(['default_binary_protocol' => true]));
        } finally {
            restore_error_handler();
        }

        self::assertContains(
            'memcached.default_binary_protocol=On is ignored: PureCache speaks the meta protocol exclusively',
            $warnings,
        );
        self::assertTrue($core->options[MemcachedConstants::OPT_BINARY_PROTOCOL]);
    }

    public function testApplyIniDefaultsConnectTimeoutOnlyAppliedWhenNonZero(): void
    {
        $core = MemcachedClientCore::createFresh();
        $before = $core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT] ?? null;

        $core->applyIniDefaults($this->snapshot(['default_connect_timeout' => 0]));
        self::assertSame($before, $core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT] ?? null);

        $core->applyIniDefaults($this->snapshot(['default_connect_timeout' => 2500]));
        self::assertSame(2500, $core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT]);
    }

    public function testOptionIntReturnsStoredIntegerAndFallsBackForWrongTypes(): void
    {
        $core = MemcachedClientCore::createFresh();

        $core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT] = 1234;
        self::assertSame(1234, $core->optionInt(MemcachedConstants::OPT_CONNECT_TIMEOUT, 7));

        $core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT] = '1234';
        self::assertSame(7, $core->optionInt(MemcachedConstants::OPT_CONNECT_TIMEOUT, 7));

        unset($core->options[MemcachedConstants::OPT_CONNECT_TIMEOUT]);
        self::assertSame(11, $core->optionInt(MemcachedConstants::OPT_CONNECT_TIMEOUT, 11));
    }

    public function testOptionBoolReturnsStoredBoolAndFallsBackForWrongTypes(): void
    {
        $core = MemcachedClientCore::createFresh();

        $core->options[MemcachedConstants::OPT_TCP_NODELAY] = true;
        self::assertTrue($core->optionBool(MemcachedConstants::OPT_TCP_NODELAY, false));

        $core->options[MemcachedConstants::OPT_TCP_NODELAY] = 1;
        self::assertFalse($core->optionBool(MemcachedConstants::OPT_TCP_NODELAY, false));

        unset($core->options[MemcachedConstants::OPT_TCP_NODELAY]);
        self::assertTrue($core->optionBool(MemcachedConstants::OPT_TCP_NODELAY, true));
    }

    /**
     * Build the well-typed INI snapshot {@see ClientCoreState::applyIniDefaults()}
     * expects, defaulting every field to a "neutral" value so individual tests
     * only need to override the fields under test.
     *
     * @param array<string, mixed> $overrides
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
    private function snapshot(array $overrides): array
    {
        $base = [
            'serializer' => ClientOptions::defaultSerializer(),
            'compression_type' => MemcachedConstants::COMPRESSION_TYPE_FASTLZ,
            'compression_level' => 3,
            'compression_threshold' => 2000,
            'compression_factor' => 1.3,
            'store_retry_count' => 0,
            'item_size_limit' => 0,
            'default_consistent_hash' => false,
            'default_binary_protocol' => false,
            'default_connect_timeout' => 0,
        ];

        /**
         * @var array{
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
         * } $merged
         */
        $merged = array_replace($base, $overrides);

        return $merged;
    }
}
