<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;
use PureMemcached\Internal\MetaReader;
use PureMemcached\Internal\MetaResult;
use PureMemcached\Internal\StreamConnection;

final class MemcachedClientStateTest extends TestCase
{
    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketConnection(string $serverData): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$client, $server] = $pair;
        fwrite($server, $serverData);

        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $client);

        return [$connection, $server];
    }

    public function testExtendedValueIncludesZeroCasAndFlagsLikePecl(): void
    {
        $client = new MemcachedClient();
        $method = new \ReflectionMethod(MemcachedClient::class, 'extendedValue');

        $extended = $method->invoke($client, 'value', new MetaResult('VA', ['f' => '0'], null));

        self::assertSame([
            'value' => 'value',
            'cas' => 0,
            'flags' => 0,
        ], $extended);
    }

    public function testDelayedEntryIncludesZeroCasAndFlagsWhenRequested(): void
    {
        $client = new MemcachedClient();
        $method = new \ReflectionMethod(MemcachedClient::class, 'delayedEntry');

        $entry = $method->invoke($client, 'key', 'value', new MetaResult('VA', ['f' => '0'], null), true);

        self::assertSame([
            'key' => 'key',
            'value' => 'value',
            'cas' => 0,
            'flags' => 0,
        ], $entry);
    }

    public function testDelayedCallbackIncludesZeroCasAndFlagsWhenRequested(): void
    {
        [$connection, $server] = $this->socketConnection("VA 5 f0\r\nvalue\r\n");
        $client = new MemcachedClient();
        $method = new \ReflectionMethod(MemcachedClient::class, 'dispatchDelayedValueCb');
        $seen = null;

        try {
            $method->invoke($client, new MetaReader($connection), 'key', true, static function (MemcachedClient $client, array $item) use (&$seen): void {
                $seen = $item;
            });
        } finally {
            fclose($server);
        }

        self::assertSame([
            'key' => 'key',
            'value' => 'value',
            'cas' => 0,
            'flags' => 0,
        ], $seen);
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

    public function testSetBucketValidationAndSuccess(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);
        $client->addServer('127.0.0.2', 11212);

        self::assertTrue($client->setBucket([0, 1], null, 0));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            self::assertFalse($client->setBucket([], null, 0));
        } finally {
            restore_error_handler();
        }

        self::assertSame('Memcached::setBucket(): server map cannot be empty', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsMismatchedForwardMap(): void
    {
        $client = new MemcachedClient();

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            self::assertFalse($client->setBucket([0, 1], [0], 0));
        } finally {
            restore_error_handler();
        }

        self::assertSame('Memcached::setBucket(): forward_map length must match the server_map length', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsNegativeReplicas(): void
    {
        $client = new MemcachedClient();

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            self::assertFalse($client->setBucket([0], null, -1));
        } finally {
            restore_error_handler();
        }

        self::assertSame('Memcached::setBucket(): replicas must be larger than zero', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testSetBucketRejectsNegativeMapValues(): void
    {
        $client = new MemcachedClient();
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            self::assertFalse($client->setBucket([-1], null, 1));
        } finally {
            restore_error_handler();
        }

        self::assertSame('Memcached::setBucket(): the map must contain positive integers', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testDefaultAndUpdatedOptions(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->getOption(MemcachedClient::OPT_COMPRESSION));
        self::assertSame(MemcachedClient::SERIALIZER_PHP, $client->getOption(MemcachedClient::OPT_SERIALIZER));

        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'prefix:'));
        self::assertSame('prefix:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, ''));
        self::assertSame('', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));

        self::assertFalse($client->setOption(MemcachedClient::OPT_BINARY_PROTOCOL, true));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertFalse($client->getOption(MemcachedClient::OPT_BINARY_PROTOCOL));
        self::assertTrue($client->setOption(MemcachedClient::OPT_NOREPLY, true));
        self::assertTrue($client->getOption(MemcachedClient::OPT_NOREPLY));
        self::assertTrue($client->setOption(MemcachedClient::OPT_BUFFER_WRITES, true));
        self::assertTrue($client->getOption(MemcachedClient::OPT_BUFFER_WRITES));
        self::assertTrue($client->setOption(MemcachedClient::OPT_HASH_WITH_PREFIX_KEY, true));
        self::assertTrue($client->getOption(MemcachedClient::OPT_HASH_WITH_PREFIX_KEY));
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
        self::assertSame(MemcachedClient::SERIALIZER_PHP, $client->getOption(MemcachedClient::OPT_SERIALIZER));

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
        $c1 = new MemcachedClient($id, static function (MemcachedClient $m, ?string $pid) use (&$calls): void {
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
        self::assertTrue($client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE));
        self::assertSame(MemcachedClient::DISTRIBUTION_CONSISTENT, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_HASH));

        self::assertTrue($client->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, false));
        self::assertFalse($client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE));
        self::assertSame(MemcachedClient::DISTRIBUTION_MODULA, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertSame(MemcachedClient::HASH_DEFAULT, $client->getOption(MemcachedClient::OPT_HASH));
    }

    public function testUnsupportedOptionsAreRejectedWithoutMutatingState(): void
    {
        $client = new MemcachedClient();

        foreach ([
            MemcachedClient::OPT_USE_UDP,
            MemcachedClient::OPT_NO_BLOCK,
            MemcachedClient::OPT_TCP_KEEPALIVE,
            MemcachedClient::OPT_SORT_HOSTS,
            MemcachedClient::OPT_REMOVE_FAILED_SERVERS,
            MemcachedClient::OPT_RANDOMIZE_REPLICA_READ,
            MemcachedClient::OPT_CORK,
        ] as $option) {
            self::assertFalse($client->setOption($option, true));
            self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
            self::assertNotTrue($client->getOption($option));
        }

        foreach ([
            MemcachedClient::OPT_STORE_RETRY_COUNT,
            MemcachedClient::OPT_RETRY_TIMEOUT,
            MemcachedClient::OPT_DEAD_TIMEOUT,
            MemcachedClient::OPT_POLL_TIMEOUT,
            MemcachedClient::OPT_SERVER_FAILURE_LIMIT,
            MemcachedClient::OPT_SERVER_TIMEOUT_LIMIT,
            MemcachedClient::OPT_NUMBER_OF_REPLICAS,
            MemcachedClient::OPT_SOCKET_SEND_SIZE,
            MemcachedClient::OPT_SOCKET_RECV_SIZE,
            MemcachedClient::OPT_IO_BYTES_WATERMARK,
            MemcachedClient::OPT_IO_KEY_PREFETCH,
            MemcachedClient::OPT_IO_MSG_WATERMARK,
        ] as $option) {
            self::assertFalse($client->setOption($option, 2));
            self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        }

        self::assertSame(0, $client->getOption(MemcachedClient::OPT_STORE_RETRY_COUNT));
        self::assertSame(0, $client->getOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS));
        self::assertTrue($client->setOption(MemcachedClient::OPT_USER_DATA, ['user' => true]));
        self::assertSame(['user' => true], $client->getOption(MemcachedClient::OPT_USER_DATA));
        self::assertFalse($client->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, MemcachedClient::HASH_MD5));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
    }

    public function testLocallyImplementedBooleanOptionsAreStored(): void
    {
        $client = new MemcachedClient();

        foreach ([
            MemcachedClient::OPT_TCP_NODELAY,
            MemcachedClient::OPT_VERIFY_KEY,
            MemcachedClient::OPT_HASH_WITH_PREFIX_KEY,
            MemcachedClient::OPT_NOREPLY,
            MemcachedClient::OPT_BUFFER_WRITES,
        ] as $option) {
            self::assertTrue($client->setOption($option, true));
            self::assertTrue($client->getOption($option));
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

        self::assertFalse($client->setEncodingKey('secret'));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertSame('encoding not supported', $client->getResultMessage());
        self::assertSame('encoding not supported', $client->getLastErrorMessage());
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getLastErrorCode());
        self::assertSame(0, $client->getLastErrorErrno());
        self::assertFalse($client->getLastDisconnectedServer());

        self::assertFalse($client->setSaslAuthData('user', 'pass'));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
    }

    public function testNegativeIncrementOffsetReturnsFalseAndSetsInvalidArguments(): void
    {
        $client = new MemcachedClient();
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $client->increment('counter', -1);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertSame('offset cannot be a negative value', $warning);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testAppendAndPrependRejectCompression(): void
    {
        $client = new MemcachedClient();
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $client->append('key', 'suffix');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertSame('cannot append/prepend with compression turned on', $warning);
        self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (\E_USER_WARNING === $severity) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $client->prepend('key', 'prefix');
        } finally {
            restore_error_handler();
        }

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

        if (!\extension_loaded('igbinary')) {
            self::assertFalse($client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
            self::assertSame(MemcachedClient::SERIALIZER_PHP, $client->getOption(MemcachedClient::OPT_SERIALIZER));
            $tested = true;
        }

        if (!\extension_loaded('msgpack')) {
            self::assertFalse($client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_MSGPACK));
            self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
            self::assertSame(MemcachedClient::SERIALIZER_PHP, $client->getOption(MemcachedClient::OPT_SERIALIZER));
            $tested = true;
        }

        if (!$tested) {
            self::markTestSkipped('optional serializers are available');
        }
    }
}
