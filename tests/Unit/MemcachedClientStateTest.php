<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\AbstractCacheClient;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\ClientOptions;
use PureCache\Internal\IniConfig;
use PureCache\Internal\LibketamaHashOptionParity;
use PureCache\Memcached\MemcachedClient;

final class MemcachedClientStateTest extends TestCase
{
    public function testExtendedValueIncludesZeroCasAndFlagsLikePecl(): void
    {
        $client = new MemcachedClient();
        $method = new \ReflectionMethod(AbstractCacheClient::class, 'valueForGetFlags');

        $extended = $method->invoke($client, new CacheEntry('value', 0, 0), MemcachedClient::GET_EXTENDED);

        self::assertSame([
            'value' => 'value',
            'cas' => 0,
            'flags' => 0,
        ], $extended);
    }

    public function testDelayedEntryIncludesZeroCasAndFlagsWhenRequested(): void
    {
        $client = new MemcachedClient();
        $method = new \ReflectionMethod(AbstractCacheClient::class, 'delayedEntry');

        $entry = $method->invoke($client, 'key', new CacheEntry('value', 0, 0), true);

        self::assertSame([
            'key' => 'key',
            'value' => 'value',
            'cas' => 0,
            'flags' => 0,
        ], $entry);
    }

    public function testServerListLifecycle(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->addServer('127.0.0.1', 11211, 2));
        self::assertSame([
            ['host' => '127.0.0.1', 'port' => 11211, 'type' => 'TCP', 'weight' => 2],
        ], $client->getServerList());

        self::assertTrue($client->resetServerList());
        self::assertSame([], $client->getServerList());
    }

    public function testAddServerNormalizesDefaultsAndRejectsInvalidPort(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->addServer('', 0));
        self::assertSame([
            ['host' => 'localhost', 'port' => 11211, 'type' => 'TCP', 'weight' => 0],
        ], $client->getServerList());

        self::assertFalse($client->addServer('127.0.0.1', -1));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testComposerAutoloadProvidesGlobalAliasWhenExtensionIsAbsent(): void
    {
        self::assertTrue(class_exists('Memcached'));
    }

    public function testAddServersAcceptsNumericAndNamedEntries(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->addServers([
            ['127.0.0.1', 11211, 2],
            ['host' => '127.0.0.2', 'port' => 11212, 'weight' => 3],
        ]));

        self::assertSame([
            ['host' => '127.0.0.1', 'port' => 11211, 'type' => 'TCP', 'weight' => 2],
            ['host' => '127.0.0.2', 'port' => 11212, 'type' => 'TCP', 'weight' => 3],
        ], $client->getServerList());

        $server = $client->getServerByKey('route');
        self::assertIsArray($server);
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testAddServersRejectsInvalidEntries(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->addServers([['host' => '127.0.0.1']]));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
        self::assertSame('invalid server entry', $client->getResultMessage());
    }

    public function testGetLastErrorMessageMatchesResultSurfaceAfterControlledFailure(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->addServers([['host' => '127.0.0.1']]));
        self::assertNotSame('', $client->getLastErrorMessage());
        self::assertSame($client->getResultMessage(), $client->getLastErrorMessage());
    }

    public function testSetBucketValidationAndSuccess(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);
        $client->addServer('127.0.0.2', 11212);

        self::assertTrue($client->setBucket([0, 1], null, 0));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());

        $warning = $this->captureUserWarning(static function () use ($client): void {
            self::assertFalse($client->setBucket([], null, 0));
        });

        self::assertSame('Memcached::setBucket(): server map cannot be empty', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsMismatchedForwardMap(): void
    {
        $client = new MemcachedClient();

        $warning = $this->captureUserWarning(static function () use ($client): void {
            self::assertFalse($client->setBucket([0, 1], [0], 0));
        });

        self::assertSame('Memcached::setBucket(): forward_map length must match the server_map length', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsNegativeReplicas(): void
    {
        $client = new MemcachedClient();

        $warning = $this->captureUserWarning(static function () use ($client): void {
            self::assertFalse($client->setBucket([0], null, -1));
        });

        self::assertSame('Memcached::setBucket(): replicas must be larger than zero', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsNegativeMapValues(): void
    {
        $client = new MemcachedClient();

        $warning = $this->captureUserWarning(static function () use ($client): void {
            self::assertFalse($client->setBucket([-1], null, 1));
        });

        self::assertSame('Memcached::setBucket(): the map must contain positive integers', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketCoercesFloatStringAndBoolMapValuesLikePecl(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);
        $client->addServer('127.0.0.2', 11212);

        self::assertTrue($client->setBucket([0, '1', 1.7, true, false], null, 1));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testSetBucketRejectsNegativeFloatAndStringMapValues(): void
    {
        $client = new MemcachedClient();

        $warning = $this->captureUserWarning(static function () use ($client): void {
            self::assertFalse($client->setBucket([0, '-2'], null, 1));
            self::assertFalse($client->setBucket([0, -1.5], null, 1));
        });

        self::assertSame('Memcached::setBucket(): the map must contain positive integers', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testDefaultAndUpdatedOptions(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->getOption(MemcachedClient::OPT_COMPRESSION));
        self::assertSame(IniConfig::snapshot()['serializer'], $client->getOption(MemcachedClient::OPT_SERIALIZER));

        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'prefix:'));
        self::assertSame('prefix:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, ''));
        self::assertSame('', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));

        self::assertFalse($client->setOption(MemcachedClient::OPT_BINARY_PROTOCOL, true));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertFalse($client->getOption(MemcachedClient::OPT_BINARY_PROTOCOL));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_NO_BLOCK));
        self::assertTrue($client->setOption(MemcachedClient::OPT_NO_BLOCK, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_NO_BLOCK));
        self::assertTrue($client->setOption(MemcachedClient::OPT_NOREPLY, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_NOREPLY));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_TCP_KEEPALIVE, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_BUFFER_WRITES, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_BUFFER_WRITES));
        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH_WITH_PREFIX_KEY, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_HASH_WITH_PREFIX_KEY));
        self::assertTrue($client->setOption(MemcachedClient::OPT_DISTRIBUTION, MemcachedClient::DISTRIBUTION_CONSISTENT));
        self::assertSame(MemcachedClient::DISTRIBUTION_CONSISTENT, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_CRC));
        self::assertSame(MemcachedClient::HASH_CRC, $client->getOption(MemcachedClient::OPT_HASH));
        self::assertTrue($client->setOption(MemcachedClient::OPT_CONNECT_TIMEOUT, 250));
        self::assertSame(250, $client->getOption(MemcachedClient::OPT_CONNECT_TIMEOUT));
        self::assertTrue($client->setOption(MemcachedClient::OPT_RECV_TIMEOUT, 500));
        self::assertSame(500, $client->getOption(MemcachedClient::OPT_RECV_TIMEOUT));
        self::assertTrue($client->setOption(MemcachedClient::OPT_USER_FLAGS, 7));
        self::assertSame(7, $client->getOption(MemcachedClient::OPT_USER_FLAGS));
        self::assertFalse($client->setOption(123456, 'custom'));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        self::assertNull($client->getOption(123456));
    }

    public function testInvalidOptionValuesDoNotMutateState(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'bad prefix'));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
        self::assertSame('', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));

        self::assertFalse($client->setOption(MemcachedClient::OPT_SERIALIZER, 999));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        self::assertSame(IniConfig::snapshot()['serializer'], $client->getOption(MemcachedClient::OPT_SERIALIZER));

        self::assertFalse($client->setOption(MemcachedClient::OPT_COMPRESSION_TYPE, 999));
        self::assertSame(38, $client->getResultCode());
        self::assertSame(MemcachedClient::COMPRESSION_TYPE_FASTLZ, $client->getOption(MemcachedClient::OPT_COMPRESSION_TYPE));

        self::assertFalse($client->setOption(MemcachedClient::OPT_COMPRESSION_LEVEL, 10));
        self::assertSame(38, $client->getResultCode());
        self::assertSame(3, $client->getOption(MemcachedClient::OPT_COMPRESSION_LEVEL));

        self::assertFalse($client->setOption(MemcachedClient::OPT_CONNECT_TIMEOUT, -1));
        self::assertSame(38, $client->getResultCode());
        self::assertSame(1000, $client->getOption(MemcachedClient::OPT_CONNECT_TIMEOUT));

        self::assertFalse($client->setOption(MemcachedClient::OPT_USER_FLAGS, 0x10000));
        self::assertSame(38, $client->getResultCode());
        self::assertSame(-1, $client->getOption(MemcachedClient::OPT_USER_FLAGS));

        self::assertFalse($client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, -1));
        self::assertSame(38, $client->getResultCode());
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT));
    }

    public function testConstructorCallbackAndPersistentState(): void
    {
        $called = false;
        $client = new MemcachedClient('persist-id', static function (MemcachedClient $client, ?string $id) use (&$called): void {
            $called = true;
            self::assertSame('persist-id', $id);
            $client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'cb:');
        });

        self::assertTrue($called);
        self::assertTrue($client->isPersistent());
        self::assertTrue($client->isPristine());
        self::assertSame('cb:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
        self::assertTrue($client->quit());
    }

    public function testConstructorCallbackExceptionIsRethrown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback failed');

        new MemcachedClient(null, static function (): never {
            throw new \RuntimeException('callback failed');
        });
    }

    public function testPersistentReuseSharesStateAndSkipsConstructorCallback(): void
    {
        $calls = 0;
        $id = 'persist-share-'.bin2hex(random_bytes(4));
        $c1 = new MemcachedClient($id, static function (MemcachedClient $m, ?string $_pid) use (&$calls): void {
            ++$calls;
            $m->setOption(MemcachedClient::OPT_PREFIX_KEY, 'shared:');
        });
        self::assertSame(1, $calls);

        $c2 = new MemcachedClient($id, static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls);
        self::assertFalse($c2->isPristine());
        self::assertSame('shared:', $c2->getOption(MemcachedClient::OPT_PREFIX_KEY));

        $c2->setOption(MemcachedClient::OPT_PREFIX_KEY, 'mutated:');
        self::assertSame('mutated:', $c1->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testPersistentReuseSurvivesObjectDestructionInSameProcess(): void
    {
        $calls = 0;
        $id = 'persist-lifetime-'.bin2hex(random_bytes(4));
        $client = new MemcachedClient($id, static function (MemcachedClient $m) use (&$calls): void {
            ++$calls;
            $m->setOption(MemcachedClient::OPT_PREFIX_KEY, 'kept:');
        });
        self::assertTrue($client->isPristine());
        unset($client);

        $client = new MemcachedClient($id, static function () use (&$calls): void {
            ++$calls;
        });

        self::assertSame(1, $calls);
        self::assertFalse($client->isPristine());
        self::assertSame('kept:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testConnectionStringAddsServers(): void
    {
        $client = new MemcachedClient(null, null, '127.0.0.1:11211,10.0.0.2:11212:3');
        self::assertSame([
            ['host' => '127.0.0.1', 'port' => 11211, 'type' => 'TCP', 'weight' => 0],
            ['host' => '10.0.0.2', 'port' => 11212, 'type' => 'TCP', 'weight' => 3],
        ], $client->getServerList());
    }

    public function testVirtualBucketForwardMapOverridesHostMap(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.1', 11211);
        $client->addServer('10.0.0.2', 11212);
        self::assertTrue($client->setBucket([0, 0], [1, 1], 0));
        $s = $client->getServerByKey('any-routing-key');
        self::assertIsArray($s);
        self::assertSame('10.0.0.2', $s['host']);
        self::assertSame(11212, $s['port']);
    }

    public function testLibketamaOptionUpdatesDependentOptions(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE));
        self::assertSame(MemcachedClient::DISTRIBUTION_CONSISTENT, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_HASH));

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, false));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE));
        self::assertSame(MemcachedClient::DISTRIBUTION_MODULA, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertSame(MemcachedClient::HASH_DEFAULT, $client->getOption(MemcachedClient::OPT_HASH));
    }

    public function testUnsupportedOptionsAreRejectedWithoutMutatingState(): void
    {
        $client = new MemcachedClient();

        // OPT_USE_UDP and OPT_BINARY_PROTOCOL are the only OPT_* dials that
        // describe transports PureCache does not speak — the meta-protocol
        // client is binary-safe over plain TCP. Everything else exposed by
        // {@see MemcachedConstants} now lands somewhere observable (storage,
        // selector, failure tracker, file loader, …); see the per-option
        // tests below for the contracts.
        foreach ([
            MemcachedClient::OPT_USE_UDP,
            MemcachedClient::OPT_BINARY_PROTOCOL,
        ] as $option) {
            self::assertFalse($client->setOption($option, true));
            self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
            self::assertNotTrue($client->getOption($option));
        }

        self::assertTrue($client->setOption(MemcachedClient::OPT_SOCKET_SEND_SIZE, 8192));
        self::assertSame(8192, $client->getOption(MemcachedClient::OPT_SOCKET_SEND_SIZE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_SOCKET_RECV_SIZE, 8192));
        self::assertSame(8192, $client->getOption(MemcachedClient::OPT_SOCKET_RECV_SIZE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_USER_DATA, ['user' => true]));
        self::assertSame(['user' => true], $client->getOption(MemcachedClient::OPT_USER_DATA));
    }

    /**
     * Previously libmemcached's failover/tuning dials were rejected
     * wholesale with RES_NOT_SUPPORTED. PureCache now wires each one into the
     * shared selector / failure tracker / transport surface, so the contract
     * is: setOption succeeds, getOption echoes the value back, negative
     * integers are rejected with RES_INVALID_ARGUMENTS.
     */
    public function testFailoverAndTuningOptionsAreAccepted(): void
    {
        $client = new MemcachedClient();

        foreach ([
            MemcachedClient::OPT_SORT_HOSTS,
            MemcachedClient::OPT_REMOVE_FAILED_SERVERS,
            MemcachedClient::OPT_RANDOMIZE_REPLICA_READ,
            MemcachedClient::OPT_CORK,
        ] as $boolOption) {
            self::assertTrue($client->setOption($boolOption, true), \sprintf('option %d should accept true', $boolOption));
            self::assertTrue((bool) $client->getOption($boolOption));
            self::assertTrue($client->setOption($boolOption, false));
            self::assertFalse((bool) $client->getOption($boolOption));
        }

        foreach ([
            MemcachedClient::OPT_STORE_RETRY_COUNT => 3,
            MemcachedClient::OPT_RETRY_TIMEOUT => 5,
            MemcachedClient::OPT_DEAD_TIMEOUT => 30,
            MemcachedClient::OPT_POLL_TIMEOUT => 1500,
            MemcachedClient::OPT_SERVER_FAILURE_LIMIT => 4,
            MemcachedClient::OPT_SERVER_TIMEOUT_LIMIT => 2,
            MemcachedClient::OPT_NUMBER_OF_REPLICAS => 2,
            MemcachedClient::OPT_IO_BYTES_WATERMARK => 65536,
            MemcachedClient::OPT_IO_KEY_PREFETCH => 8,
            MemcachedClient::OPT_IO_MSG_WATERMARK => 16,
        ] as $option => $value) {
            self::assertTrue($client->setOption($option, $value), \sprintf('option %d should accept %d', $option, $value));
            self::assertSame($value, $client->getOption($option));
            self::assertFalse($client->setOption($option, -1));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
            self::assertSame($value, $client->getOption($option));
        }
    }

    /**
     * PECL surfaces {@code OPT_LIBKETAMA_HASH} via libmemcached's separate
     * {@code MEMCACHED_BEHAVIOR_KETAMA_HASH} dial. It tracks {@code OPT_HASH}
     * only on a fresh client and after the {@code OPT_LIBKETAMA_COMPATIBLE}
     * cascade — direct {@code OPT_HASH} writes do not move the ketama getter.
     */
    public function testLibketamaHashGetterTracksOptHashAcrossCascade(): void
    {
        $client = new MemcachedClient();

        self::assertSame(
            $client->getOption(MemcachedClient::OPT_HASH),
            $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
        );

        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_CRC));
        self::assertSame(MemcachedClient::HASH_CRC, $client->getOption(MemcachedClient::OPT_HASH));
        self::assertSame(MemcachedClient::HASH_CRC, $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH));

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, true));
        self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH));

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, false));
        self::assertSame(MemcachedClient::HASH_DEFAULT, $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function libketamaHashAcceptedValues(): array
    {
        return [
            'int_md5' => [MemcachedClient::HASH_MD5],
            'int_crc' => [MemcachedClient::HASH_CRC],
            'int_murmur' => [MemcachedClient::HASH_MURMUR],
            'int_bogus' => [9999],
            'int_negative' => [-3],
            'string_numeric' => ['5'],
            'string_mixed' => ['3abc'],
            'string_empty' => [''],
            'string_word' => ['not-an-int'],
            'null' => [null],
            'bool_true' => [true],
            'bool_false' => [false],
            'float_safe' => [1.5],
            'array_nonempty' => [[1, 2]],
            'array_empty' => [[]],
        ];
    }

    /**
     * PECL routes OPT_LIBKETAMA_HASH through {@code zval_get_long()} →
     * libmemcached → hashkit. Hashkit accepts every coerced long except
     * HASH_HSIEH (PECL builds without HAVE_HSIEH_HASH), and the dial
     * never changes routing. On ext-memcached 3.4.x the getter still reports
     * {@code OPT_HASH}; on older builds it surfaces the coerced ketama value.
     * Mirror that no-op-success contract so callers porting from ext-memcached
     * see identical setOption returns.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('libketamaHashAcceptedValues')]
    public function testLibketamaHashSetterAcceptsAnyPeclCoercibleValueAsNoop(mixed $value): void
    {
        $client = new MemcachedClient();
        // Anchor OPT_HASH at a known value so we can prove the setter
        // didn't move it.
        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_MURMUR));

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, $value));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
        self::assertSame(MemcachedClient::HASH_MURMUR, $client->getOption(MemcachedClient::OPT_HASH));

        self::assertSame(
            LibketamaHashOptionParity::expectedGetterAfterLibketamaSetter(
                $value,
                MemcachedClient::HASH_MURMUR,
            ),
            $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function libketamaHashRejectedValues(): array
    {
        return [
            'int_hsieh' => [MemcachedClient::HASH_HSIEH],
            'string_hsieh' => ['7'],
            'float_truncates_to_hsieh' => [7.9],
        ];
    }

    /**
     * PECL builds libmemcached without HAVE_HSIEH_HASH, so the hashkit
     * setter rejects HASH_HSIEH with INVALID_ARGUMENT. PHP-side coercion
     * runs first, so a float like 7.9 or the string "7" also lands on
     * HSIEH after {@code zval_get_long()} and must be rejected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('libketamaHashRejectedValues')]
    public function testLibketamaHashSetterRejectsHsiehInAllPeclCoercions(mixed $value): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, $value));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testLibketamaHashSetterNormalizesOutOfRangeValuesWhenPeclSurfacesStoredDial(): void
    {
        $surfacesStoredDial = new \ReflectionProperty(
            LibketamaHashOptionParity::class,
            'setterSurfacesCoercedGetterWithoutCompat',
        );
        $previous = $surfacesStoredDial->getValue();
        $surfacesStoredDial->setValue(null, true);

        try {
            foreach ([9999, -3] as $value) {
                $client = new MemcachedClient();
                self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_MURMUR));
                self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, $value));
                self::assertSame(
                    MemcachedClient::HASH_DEFAULT,
                    $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
                );

                self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_CRC));
                self::assertSame(
                    MemcachedClient::HASH_CRC,
                    $client->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
                );
            }
        } finally {
            $surfacesStoredDial->setValue(null, $previous);
        }
    }

    /**
     * Behavioural anchor for the "no-op setter" claim: a real
     * three-server ring keeps the same key→shard mapping across a series
     * of OPT_LIBKETAMA_HASH writes (including non-int coerced ones).
     * Without this, a future refactor could silently make
     * OPT_LIBKETAMA_HASH change the selector hash — diverging from PECL
     * even though every other surface check still passes.
     */
    public function testLibketamaHashSetterIsNoopForRoutingDecisions(): void
    {
        $client = new MemcachedClient();
        $client->addServer('cache-a', 11211);
        $client->addServer('cache-b', 11211);
        $client->addServer('cache-c', 11211);
        self::assertTrue($client->setOption(MemcachedClient::OPT_DISTRIBUTION, MemcachedClient::DISTRIBUTION_CONSISTENT));

        $keys = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta'];
        $baseline = $this->resolveServerHosts($client, $keys);

        // Even a "valid-looking" int that would otherwise mean MURMUR
        // must leave routing alone (the dial is purely PECL-cosmetic).
        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, MemcachedClient::HASH_MURMUR));
        self::assertSame($baseline, $this->resolveServerHosts($client, $keys));

        // A coerced non-int (PHP-string) must also stay no-op.
        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, 'not-an-int'));
        self::assertSame($baseline, $this->resolveServerHosts($client, $keys));

        // OPT_HASH still works as the actual routing dial; flipping it
        // must move at least one key — proving the test would catch a
        // regression where OPT_LIBKETAMA_HASH wired itself into routing.
        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH, MemcachedClient::HASH_MURMUR));
        self::assertNotSame($baseline, $this->resolveServerHosts($client, $keys));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function resolveServerHosts(MemcachedClient $client, array $keys): array
    {
        $map = [];
        foreach ($keys as $key) {
            $pick = $client->getServerByKey($key);
            self::assertIsArray($pick);
            $map[$key] = $pick['host'];
        }

        return $map;
    }

    /**
     * Run {@code $body} with a {@code set_error_handler} that captures the
     * first {@code E_USER_WARNING} message raised inside it. Restores the
     * previous handler unconditionally so a failing inner assertion never
     * leaks state into the next test.
     */
    private function captureUserWarning(\Closure $body): ?string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $body();
        } finally {
            restore_error_handler();
        }

        return $warning;
    }

    public function testLocallyImplementedBooleanOptionsAreStored(): void
    {
        $client = new MemcachedClient();

        foreach ([
            MemcachedClient::OPT_TCP_NODELAY,
            MemcachedClient::OPT_TCP_KEEPALIVE,
            MemcachedClient::OPT_NO_BLOCK,
            MemcachedClient::OPT_VERIFY_KEY,
            MemcachedClient::OPT_HASH_WITH_PREFIX_KEY,
            MemcachedClient::OPT_NOREPLY,
            MemcachedClient::OPT_BUFFER_WRITES,
            MemcachedClient::OPT_SUPPORT_CAS,
        ] as $option) {
            self::assertTrue($client->setOption($option, true));
            self::assertSame(1, $client->getOption($option));
        }
    }

    /**
     * {@code OPT_SUPPORT_CAS} mirrors libmemcached's
     * {@code MEMCACHED_BEHAVIOR_SUPPORT_CAS}: defaults to off and is purely
     * a stored toggle. PureCache's meta-protocol always requests the CAS
     * token via the {@code c} flag, so flipping this dial does not change
     * observable behaviour — but parity callers still expect the get/set
     * roundtrip and the integer-boolean projection.
     */
    public function testSupportCasIsBooleanWithLibmemcachedDefault(): void
    {
        $client = new MemcachedClient();

        self::assertSame(0, $client->getOption(MemcachedClient::OPT_SUPPORT_CAS));
        self::assertTrue($client->setOption(MemcachedClient::OPT_SUPPORT_CAS, true));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_SUPPORT_CAS));
        self::assertTrue($client->setOption(MemcachedClient::OPT_SUPPORT_CAS, false));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_SUPPORT_CAS));
    }

    /**
     * {@code OPT_TCP_KEEPIDLE} is the seconds-of-idle interval applied via
     * {@code setsockopt(TCP_KEEPIDLE)} on Linux and the analogous
     * {@code TCP_KEEPALIVE} on macOS. The applier accepts any non-negative
     * integer, rejects negative ints / non-coercible strings, and the
     * stored value is what the next pooled connection will see — pin the
     * accepted contract here so the platform fallback inside
     * {@see \PureCache\Memcached\Internal\StreamConnection} is free to
     * silently no-op when neither knob is reachable.
     */
    public function testTcpKeepIdleAcceptsNonNegativeIntegers(): void
    {
        $client = new MemcachedClient();

        self::assertSame(0, $client->getOption(MemcachedClient::OPT_TCP_KEEPIDLE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_TCP_KEEPIDLE, 30));
        self::assertSame(30, $client->getOption(MemcachedClient::OPT_TCP_KEEPIDLE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_TCP_KEEPIDLE, '45'));
        self::assertSame(45, $client->getOption(MemcachedClient::OPT_TCP_KEEPIDLE));
        self::assertTrue($client->setOption(MemcachedClient::OPT_TCP_KEEPIDLE, 0));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_TCP_KEEPIDLE));

        self::assertFalse($client->setOption(MemcachedClient::OPT_TCP_KEEPIDLE, -1));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        self::assertFalse($client->setOption(MemcachedClient::OPT_TCP_KEEPIDLE, 'abc'));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    /**
     * {@code OPT_LOAD_FROM_FILE} runs the libmemcached configuration-file
     * DSL: server list, hashing, timeouts and tuning dials are populated
     * from a single token stream. Directives unsupported by the current
     * PureCache backend emit notices but the overall load still succeeds —
     * mirroring libmemcached's "downgrade unsupported behaviours" model so
     * a portable config file works against leaner consumers.
     */
    public function testLoadFromFilePopulatesPoolAndOptions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'purecache_libmemcached_');
        self::assertIsString($path);
        try {
            file_put_contents($path, <<<CONF
                --SERVER=primary.example.com:11215
                --SERVER=secondary.example.com:11216/?3
                --HASH=md5
                --DISTRIBUTION=consistent
                --NAMESPACE="lmcfg_"
                --TCP-NODELAY
                --TCP-KEEPALIVE
                --TCP-KEEPIDLE=75
                --SND-TIMEOUT=250
                --RCV-TIMEOUT=350
                --SUPPORT-CAS
                --POOL-MAX=8
                CONF);

            $client = new MemcachedClient();
            self::assertTrue($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $path));
            self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
            self::assertSame($path, $client->getOption(MemcachedClient::OPT_LOAD_FROM_FILE));

            self::assertSame([
                ['host' => 'primary.example.com', 'port' => 11215, 'type' => 'TCP', 'weight' => 0],
                ['host' => 'secondary.example.com', 'port' => 11216, 'type' => 'TCP', 'weight' => 3],
            ], $client->getServerList());

            self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_HASH));
            self::assertSame(
                MemcachedClient::DISTRIBUTION_CONSISTENT,
                $client->getOption(MemcachedClient::OPT_DISTRIBUTION),
            );
            self::assertSame('lmcfg_', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
            self::assertSame(1, $client->getOption(MemcachedClient::OPT_TCP_NODELAY));
            self::assertSame(1, $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE));
            self::assertSame(75, $client->getOption(MemcachedClient::OPT_TCP_KEEPIDLE));
            self::assertSame(250, $client->getOption(MemcachedClient::OPT_SEND_TIMEOUT));
            self::assertSame(350, $client->getOption(MemcachedClient::OPT_RECV_TIMEOUT));
            self::assertSame(1, $client->getOption(MemcachedClient::OPT_SUPPORT_CAS));
        } finally {
            @unlink($path);
        }
    }

    public function testLoadFromFileResetsBetweenIncludeAndConfigureFile(): void
    {
        $dir = sys_get_temp_dir();
        $inner = $dir.\DIRECTORY_SEPARATOR.'purecache_lm_inner_'.bin2hex(random_bytes(4)).'.conf';
        $outer = $dir.\DIRECTORY_SEPARATOR.'purecache_lm_outer_'.bin2hex(random_bytes(4)).'.conf';
        file_put_contents($inner, "--SERVER=inner.host:11211\n--HASH=fnv1a_64\n");
        file_put_contents($outer, <<<CONF
            --SERVER=outer-pre.host:11212
            INCLUDE "{$inner}"
            --SERVER=outer-post.host:11213
            CONF);

        try {
            $client = new MemcachedClient();
            self::assertTrue($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $outer));
            // INCLUDE is additive — outer-pre, inner, outer-post all appear.
            self::assertSame(
                ['outer-pre.host', 'inner.host', 'outer-post.host'],
                array_map(static fn (array $s): string => $s['host'], $client->getServerList()),
            );
            self::assertSame(MemcachedClient::HASH_FNV1A_64, $client->getOption(MemcachedClient::OPT_HASH));

            // RESET inside the file wipes the server list and behaviour
            // bits, matching libmemcached's documented semantics.
            $reset = $dir.\DIRECTORY_SEPARATOR.'purecache_lm_reset_'.bin2hex(random_bytes(4)).'.conf';
            file_put_contents($reset, "--SERVER=pre-reset:11211\nRESET\n--SERVER=after-reset:11212\nEND\n--SERVER=after-end:11213\n");
            try {
                $fresh = new MemcachedClient();
                self::assertTrue($fresh->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $reset));
                self::assertSame(
                    [['host' => 'after-reset', 'port' => 11212, 'type' => 'TCP', 'weight' => 0]],
                    $fresh->getServerList(),
                );
            } finally {
                @unlink($reset);
            }
        } finally {
            @unlink($inner);
            @unlink($outer);
        }
    }

    public function testLoadFromFileFailsCleanlyOnInvalidInputs(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, ''));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());

        self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, ['not-a-string']));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());

        $missing = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'purecache_lm_missing_'.bin2hex(random_bytes(6)).'.conf';
        self::assertFileDoesNotExist($missing);
        self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $missing));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());

        $unterminated = tempnam(sys_get_temp_dir(), 'purecache_lm_bad_');
        self::assertIsString($unterminated);
        try {
            file_put_contents($unterminated, '--NAMESPACE="oops');
            self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $unterminated));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        } finally {
            @unlink($unterminated);
        }

        $errFile = tempnam(sys_get_temp_dir(), 'purecache_lm_err_');
        self::assertIsString($errFile);
        try {
            file_put_contents($errFile, "--SERVER=ok.host:11211\nERROR\n");
            self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $errFile));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        } finally {
            @unlink($errFile);
        }
    }

    public function testKeyValidation(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->checkKey(''));
        self::assertFalse($client->checkKey('has space'));
        self::assertFalse($client->checkKey("has\0nul"));
        self::assertFalse($client->checkKey('Montréal'));
        self::assertFalse($client->checkKey(str_repeat('a', 251)));
        self::assertTrue($client->checkKey(str_repeat('a', 250)));
        self::assertTrue($client->checkKey('Testing'));
    }

    public function testPrefixParticipatesInKeyLengthValidation(): void
    {
        $client = new MemcachedClient();
        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, str_repeat('p', 10)));

        self::assertTrue($client->checkKey(str_repeat('a', 240)));
        self::assertFalse($client->checkKey(str_repeat('a', 241)));
    }

    public function testNoServerOperationsSetResultCode(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->getVersion());
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());

        self::assertFalse($client->getServerByKey('routing-key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());

        self::assertFalse($client->getStats());
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());

        self::assertFalse($client->flush());
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());

        self::assertFalse($client->getAllKeys());
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());

        self::assertFalse($client->get('key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->getByKey('server-key', 'key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->getMulti(['key']));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->getMultiByKey('server-key', ['key']));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->getDelayed(['key']));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->touch('key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->delete('key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
        self::assertFalse($client->increment('key'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testUnsupportedMethodsSetResultCode(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setSaslAuthData('user', 'pass'));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getLastErrorCode());
        self::assertSame(0, $client->getLastErrorErrno());
        self::assertFalse($client->getLastDisconnectedServer());
    }

    public function testNegativeIncrementOffsetReturnsFalseAndSetsInvalidArguments(): void
    {
        $client = new MemcachedClient();

        $result = false;
        $warning = $this->captureUserWarning(static function () use ($client, &$result): void {
            $result = $client->increment('counter', -1);
        });

        self::assertFalse($result);
        self::assertSame('offset cannot be a negative value', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testAppendAndPrependRejectCompression(): void
    {
        $client = new MemcachedClient();

        $result = false;
        $warning = $this->captureUserWarning(static function () use ($client, &$result): void {
            $result = $client->append('key', 'suffix');
        });

        self::assertFalse($result);
        self::assertSame('cannot append/prepend with compression turned on', $warning);
        self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());

        $warning = $this->captureUserWarning(static function () use ($client, &$result): void {
            $result = $client->prepend('key', 'prefix');
        });

        self::assertFalse($result);
        self::assertSame('cannot append/prepend with compression turned on', $warning);
        self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());
    }

    public function testSetOptionsReportsInvalidEntries(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOptions([
            MemcachedClient::OPT_PREFIX_KEY => 'ok:',
            'not-an-int' => true,
        ]));
        self::assertSame('ok:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testSetOptionsAcceptsPeclNoBlockConfigurationShape(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->setOptions([
            MemcachedClient::OPT_PREFIX_KEY => 'cfg:',
            MemcachedClient::OPT_NO_BLOCK => true,
            MemcachedClient::OPT_RECV_TIMEOUT => 3000,
            MemcachedClient::OPT_SEND_TIMEOUT => 1000,
            MemcachedClient::OPT_TCP_NODELAY => true,
            MemcachedClient::OPT_COMPRESSION => true,
            MemcachedClient::OPT_SERIALIZER => MemcachedClient::SERIALIZER_PHP,
            MemcachedClient::OPT_LIBKETAMA_COMPATIBLE => true,
        ]));

        self::assertSame('cfg:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_NO_BLOCK));
        self::assertSame(3000, $client->getOption(MemcachedClient::OPT_RECV_TIMEOUT));
        self::assertSame(1000, $client->getOption(MemcachedClient::OPT_SEND_TIMEOUT));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_TCP_NODELAY));
        self::assertTrue($client->getOption(MemcachedClient::OPT_COMPRESSION));
        self::assertSame(MemcachedClient::SERIALIZER_PHP, $client->getOption(MemcachedClient::OPT_SERIALIZER));
        self::assertSame(1, $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE));
    }

    public function testSimpleLocalStateOperations(): void
    {
        $client = new MemcachedClient();

        self::assertNull($client->getOption(123456));
        self::assertTrue($client->flushBuffers());
        self::assertFalse($client->fetch());
        self::assertSame(MemcachedClient::RES_FETCH_NOTFINISHED, $client->getResultCode());
        self::assertFalse($client->fetchAll());
        self::assertSame(MemcachedClient::RES_FETCH_NOTFINISHED, $client->getResultCode());
    }

    public function testNoServerStorageFailsWithoutNetwork(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->set('key', 'value'));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testNetworkFailuresReturnFalseAndSetResultCode(): void
    {
        $client = new MemcachedClient();
        $client->setOption(MemcachedClient::OPT_CONNECT_TIMEOUT, 50);
        $client->addServer('127.0.0.1', 1);

        self::assertFalse($client->set('key', 'value'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
        self::assertGreaterThan(0, $client->getLastErrorErrno());
        self::assertSame([
            'host' => '127.0.0.1',
            'port' => 1,
            'weight' => 0,
            'type' => 'TCP',
        ], $client->getLastDisconnectedServer());

        self::assertFalse($client->getMulti(['key']));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());

        self::assertFalse($client->delete('key'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());

        self::assertSame(['127.0.0.1:1' => ''], $client->getVersion());
        self::assertSame(19, $client->getResultCode());

        self::assertSame(['127.0.0.1:1' => false], $client->getStats());
        self::assertSame(19, $client->getResultCode());

        self::assertFalse($client->getAllKeys());
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());

        self::assertFalse($client->increment('key'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testLocalValidationBeforeNetworkAccess(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 1));
        self::assertFalse($client->set('too_large', 'xx'));
        self::assertSame(MemcachedClient::RES_E2BIG, $client->getResultCode());

        $client = new MemcachedClient();
        self::assertTrue($client->setOption(MemcachedClient::OPT_VERIFY_KEY, true));
        self::assertFalse($client->set('has space', 'value'));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());

        self::assertFalse($client->get('has space'));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
        self::assertFalse($client->getMulti(['has space']));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
        self::assertFalse($client->setMulti(['valid' => 'ok', 'has space' => 'bad']));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
        self::assertFalse($client->setMultiByKey('bad route', ['valid' => 'ok']));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());

        self::assertSame([
            'valid' => MemcachedClient::RES_BAD_KEY_PROVIDED,
            'has space' => MemcachedClient::RES_BAD_KEY_PROVIDED,
        ], $client->deleteMulti(['valid', 'has space']));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
        self::assertSame([
            'valid' => MemcachedClient::RES_BAD_KEY_PROVIDED,
        ], $client->deleteMultiByKey('bad route', ['valid']));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
    }

    public function testNonScalarBulkKeyIsRejectedWithBadKeyProvided(): void
    {
        $client = new MemcachedClient();

        // PECL reports RES_BAD_KEY_PROVIDED when a bulk API receives a
        // non-stringable key. Previously we silently coerced via implicit
        // string casts, which produced confusing empty-key requests on the
        // wire; the safer contract is to bail out before any network I/O.
        self::assertFalse($client->getMulti(['valid', new \stdClass()]));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());

        self::assertFalse($client->setMulti(['valid' => 'ok'] + [42 => 'whatever']));
        // Plain integer keys round-trip through (string) like memcached
        // server-side, so this still fails for a different reason (no
        // servers), but importantly does not blow up locally.
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testStringableObjectKeyIsAcceptedForBulkApis(): void
    {
        $client = new MemcachedClient();

        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'string-from-object';
            }
        };

        // Stringable objects are valid keys (matches PECL after string cast).
        // Without any server this still returns false, but the failure code
        // must be RES_NO_SERVERS — not RES_BAD_KEY_PROVIDED.
        self::assertFalse($client->getMulti([$stringable]));
        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testDeleteTimeIsRejectedBeforeNetworkAccess(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->delete('valid', -1));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
        self::assertSame('delete time must be non-negative', $client->getResultMessage());

        self::assertFalse($client->delete('valid', 1));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertSame('delayed delete is not supported by the meta protocol', $client->getResultMessage());

        self::assertSame([
            'a' => 28,
            'b' => 28,
        ], $client->deleteMulti(['a', 'b'], 1));
        self::assertSame(28, $client->getResultCode());
    }

    public function testUnavailableOptionalSerializersReturnFalse(): void
    {
        $client = new MemcachedClient();
        $tested = false;

        if (!ClientOptions::serializerIsUsable(MemcachedClient::SERIALIZER_IGBINARY)) {
            self::assertFalse($client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
            self::assertSame(ClientOptions::defaultSerializer(), $client->getOption(MemcachedClient::OPT_SERIALIZER));
            $tested = true;
        }

        if (!ClientOptions::serializerIsUsable(MemcachedClient::SERIALIZER_MSGPACK)) {
            self::assertFalse($client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_MSGPACK));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
            self::assertSame(ClientOptions::defaultSerializer(), $client->getOption(MemcachedClient::OPT_SERIALIZER));
            $tested = true;
        }

        if (!$tested) {
            self::markTestSkipped('optional serializers are available');
        }
    }

    public function testAvailableIgbinarySerializerCanBeSelected(): void
    {
        if (!ClientOptions::serializerIsUsable(MemcachedClient::SERIALIZER_IGBINARY)) {
            self::markTestSkipped('igbinary serialize functions are not available');
        }

        $client = new MemcachedClient();

        self::assertTrue($client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
        self::assertSame(MemcachedClient::SERIALIZER_IGBINARY, $client->getOption(MemcachedClient::OPT_SERIALIZER));
    }
}
