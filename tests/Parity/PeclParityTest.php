<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Parity;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;

final class PeclParityTest extends TestCase
{
    private function host(): string
    {
        $host = getenv('MEMCACHED_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    private function port(): int
    {
        $port = getenv('MEMCACHED_TEST_PORT');

        return false !== $port ? (int) $port : 11211;
    }

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('memcached')) {
            self::markTestSkipped('PECL memcached extension is not loaded');
        }
    }

    public function testSupportedConstantsMatchPecl(): void
    {
        $constants = [
            'RES_SUCCESS',
            'RES_FAILURE',
            'RES_NOTFOUND',
            'RES_NOTSTORED',
            'RES_DATA_EXISTS',
            'RES_SOME_ERRORS',
            'RES_BAD_KEY_PROVIDED',
            'RES_INVALID_ARGUMENTS',
            'GET_EXTENDED',
            'GET_PRESERVE_ORDER',
            'OPT_COMPRESSION',
            'OPT_SERIALIZER',
            'OPT_PREFIX_KEY',
            'OPT_HASH_WITH_PREFIX_KEY',
            'OPT_NO_BLOCK',
            'OPT_TCP_KEEPALIVE',
            'OPT_SOCKET_SEND_SIZE',
            'OPT_SOCKET_RECV_SIZE',
            'OPT_USER_FLAGS',
            'SERIALIZER_PHP',
            'SERIALIZER_IGBINARY',
        ];

        foreach ($constants as $constant) {
            $peclFqn = 'Memcached::'.$constant;
            $pureFqn = MemcachedClient::class.'::'.$constant;

            if (!\defined($peclFqn)) {
                continue;
            }

            self::assertTrue(\defined($pureFqn), $pureFqn.' is not defined');
            self::assertSame(\constant($peclFqn), \constant($pureFqn), $constant);
        }
    }

    public function testSupportedOptionSetGetParity(): void
    {
        $this->assertParity(
            static fn (\Memcached $client, string $prefix): array => [
                'compressionDefault' => $client->getOption(\Memcached::OPT_COMPRESSION),
                'tcpNoDelayDefault' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'tcpNoDelaySet' => $client->setOption(\Memcached::OPT_TCP_NODELAY, true),
                'tcpNoDelayAfter' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'noBlockDefault' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'noBlockSet' => $client->setOption(\Memcached::OPT_NO_BLOCK, true),
                'noBlockAfter' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'keepaliveDefault' => $client->getOption(\Memcached::OPT_TCP_KEEPALIVE),
                'keepaliveSet' => $client->setOption(\Memcached::OPT_TCP_KEEPALIVE, true),
                'keepaliveAfter' => $client->getOption(\Memcached::OPT_TCP_KEEPALIVE),
                'sendSizeSet' => $client->setOption(\Memcached::OPT_SOCKET_SEND_SIZE, 8192),
                'sendSizeAfter' => $client->getOption(\Memcached::OPT_SOCKET_SEND_SIZE),
                'recvSizeSet' => $client->setOption(\Memcached::OPT_SOCKET_RECV_SIZE, 8192),
                'recvSizeAfter' => $client->getOption(\Memcached::OPT_SOCKET_RECV_SIZE),
            ],
            static fn (MemcachedClient $client, string $prefix): array => [
                'compressionDefault' => $client->getOption(MemcachedClient::OPT_COMPRESSION),
                'tcpNoDelayDefault' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'tcpNoDelaySet' => $client->setOption(MemcachedClient::OPT_TCP_NODELAY, true),
                'tcpNoDelayAfter' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'noBlockDefault' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'noBlockSet' => $client->setOption(MemcachedClient::OPT_NO_BLOCK, true),
                'noBlockAfter' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'keepaliveDefault' => $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE),
                'keepaliveSet' => $client->setOption(MemcachedClient::OPT_TCP_KEEPALIVE, true),
                'keepaliveAfter' => $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE),
                'sendSizeSet' => $client->setOption(MemcachedClient::OPT_SOCKET_SEND_SIZE, 8192),
                'sendSizeAfter' => $client->getOption(MemcachedClient::OPT_SOCKET_SEND_SIZE),
                'recvSizeSet' => $client->setOption(MemcachedClient::OPT_SOCKET_RECV_SIZE, 8192),
                'recvSizeAfter' => $client->getOption(MemcachedClient::OPT_SOCKET_RECV_SIZE),
            ],
        );
    }

    public function testPeclStyleSetOptionsParity(): void
    {
        $this->assertParity(
            static fn (\Memcached $client, string $prefix): array => [
                'setOptions' => $client->setOptions([
                    \Memcached::OPT_PREFIX_KEY => $prefix,
                    \Memcached::OPT_NO_BLOCK => true,
                    \Memcached::OPT_RECV_TIMEOUT => 3000,
                    \Memcached::OPT_SEND_TIMEOUT => 1000,
                    \Memcached::OPT_TCP_NODELAY => true,
                    \Memcached::OPT_COMPRESSION => true,
                    \Memcached::OPT_SERIALIZER => \Memcached::SERIALIZER_IGBINARY,
                    \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
                ]),
                'prefix' => $client->getOption(\Memcached::OPT_PREFIX_KEY),
                'noBlock' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'recvTimeout' => $client->getOption(\Memcached::OPT_RECV_TIMEOUT),
                'sendTimeout' => $client->getOption(\Memcached::OPT_SEND_TIMEOUT),
                'tcpNoDelay' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'compression' => $client->getOption(\Memcached::OPT_COMPRESSION),
                'serializer' => $client->getOption(\Memcached::OPT_SERIALIZER),
                'libketama' => $client->getOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE),
            ],
            static fn (MemcachedClient $client, string $prefix): array => [
                'setOptions' => $client->setOptions([
                    MemcachedClient::OPT_PREFIX_KEY => $prefix,
                    MemcachedClient::OPT_NO_BLOCK => true,
                    MemcachedClient::OPT_RECV_TIMEOUT => 3000,
                    MemcachedClient::OPT_SEND_TIMEOUT => 1000,
                    MemcachedClient::OPT_TCP_NODELAY => true,
                    MemcachedClient::OPT_COMPRESSION => true,
                    MemcachedClient::OPT_SERIALIZER => MemcachedClient::SERIALIZER_IGBINARY,
                    MemcachedClient::OPT_LIBKETAMA_COMPATIBLE => true,
                ]),
                'prefix' => $client->getOption(MemcachedClient::OPT_PREFIX_KEY),
                'noBlock' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'recvTimeout' => $client->getOption(MemcachedClient::OPT_RECV_TIMEOUT),
                'sendTimeout' => $client->getOption(MemcachedClient::OPT_SEND_TIMEOUT),
                'tcpNoDelay' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'compression' => $client->getOption(MemcachedClient::OPT_COMPRESSION),
                'serializer' => $client->getOption(MemcachedClient::OPT_SERIALIZER),
                'libketama' => $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE),
            ],
        );
    }

    public function testIgbinarySerializerRoundTripParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'igbinary';
                $client->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY);
                $value = [
                    'list' => [1, 2, 3],
                    'assoc' => ['enabled' => true, 'name' => 'igbinary'],
                    'nested' => [['a' => 1], ['b' => null]],
                ];

                return [
                    'serializer' => $client->getOption(\Memcached::OPT_SERIALIZER),
                    'set' => $client->set($key, $value, 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'igbinary';
                $client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY);
                $value = [
                    'list' => [1, 2, 3],
                    'assoc' => ['enabled' => true, 'name' => 'igbinary'],
                    'nested' => [['a' => 1], ['b' => null]],
                ];

                return [
                    'serializer' => $client->getOption(MemcachedClient::OPT_SERIALIZER),
                    'set' => $client->set($key, $value, 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testBasicMutationAndRetrievalParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'basic';

                return [
                    'set' => $client->set($key, ['nested' => ['ok' => true]], 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                    'delete' => $client->delete($key),
                    'deleteCode' => $client->getResultCode(),
                    'missing' => $client->get($key),
                    'missingCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'basic';

                return [
                    'set' => $client->set($key, ['nested' => ['ok' => true]], 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                    'delete' => $client->delete($key),
                    'deleteCode' => $client->getResultCode(),
                    'missing' => $client->get($key),
                    'missingCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testExtendedGetAndMultiParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $keys = [$prefix.'ext-a', $prefix.'ext-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);

                return [
                    'single' => $client->get($keys[0], null, \Memcached::GET_EXTENDED),
                    'singleCode' => $client->getResultCode(),
                    'multi' => $client->getMulti($keys, \Memcached::GET_EXTENDED | \Memcached::GET_PRESERVE_ORDER),
                    'multiCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $keys = [$prefix.'ext-a', $prefix.'ext-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);

                return [
                    'single' => $client->get($keys[0], null, MemcachedClient::GET_EXTENDED),
                    'singleCode' => $client->getResultCode(),
                    'multi' => $client->getMulti($keys, MemcachedClient::GET_EXTENDED | MemcachedClient::GET_PRESERVE_ORDER),
                    'multiCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testStorageMutationParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'mutation';

                return [
                    'add' => $client->add($key, 'b', 60),
                    'addAgain' => $client->add($key, 'ignored', 60),
                    'addAgainCode' => $client->getResultCode(),
                    'replace' => $client->replace($key, 'b', 60),
                    'prepend' => $client->prepend($key, 'a'),
                    'append' => $client->append($key, 'c'),
                    'value' => $client->get($key),
                    'touch' => $client->touch($key, 60),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'mutation';

                return [
                    'add' => $client->add($key, 'b', 60),
                    'addAgain' => $client->add($key, 'ignored', 60),
                    'addAgainCode' => $client->getResultCode(),
                    'replace' => $client->replace($key, 'b', 60),
                    'prepend' => $client->prepend($key, 'a'),
                    'append' => $client->append($key, 'c'),
                    'value' => $client->get($key),
                    'touch' => $client->touch($key, 60),
                ];
            },
        );
    }

    public function testArithmeticParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'counter';

                return [
                    'missingIncrement' => $client->increment($key, 2),
                    'missingCode' => $client->getResultCode(),
                    'initial' => $client->increment($key, 1, 0, 60),
                    'increment' => $client->increment($key, 2),
                    'decrement' => $client->decrement($key, 1),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'counter';

                return [
                    'missingIncrement' => $client->increment($key, 2),
                    'missingCode' => $client->getResultCode(),
                    'initial' => $client->increment($key, 1, 0, 60),
                    'increment' => $client->increment($key, 2),
                    'decrement' => $client->decrement($key, 1),
                ];
            },
        );
    }

    public function testDelayedFetchShapeParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $keys = [$prefix.'delay-a', $prefix.'delay-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);
                $queuedWithCas = $client->getDelayed($keys, true);
                $withCas = $client->fetchAll();
                $client->getDelayed($keys, false);
                $withoutCas = $client->fetchAll();

                return [
                    'queuedWithCas' => $queuedWithCas,
                    'withCas' => $withCas,
                    'withoutCas' => $withoutCas,
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $keys = [$prefix.'delay-a', $prefix.'delay-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);
                $queuedWithCas = $client->getDelayed($keys, true);
                $withCas = $client->fetchAll();
                $client->getDelayed($keys, false);
                $withoutCas = $client->fetchAll();

                return [
                    'queuedWithCas' => $queuedWithCas,
                    'withCas' => $withCas,
                    'withoutCas' => $withoutCas,
                ];
            },
        );
    }

    public function testServerWideResponseShapeParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $client->set($prefix.'stats', 'value', 60);

                return [
                    'version' => self::normalizeVersionMap($client->getVersion()),
                    'versionCode' => $client->getResultCode(),
                    'stats' => self::normalizeStatsMap($client->getStats()),
                    'statsCode' => $client->getResultCode(),
                    'items' => self::normalizeStatsMap($client->getStats('items')),
                    'itemsCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $client->set($prefix.'stats', 'value', 60);

                return [
                    'version' => self::normalizeVersionMap($client->getVersion()),
                    'versionCode' => $client->getResultCode(),
                    'stats' => self::normalizeStatsMap($client->getStats()),
                    'statsCode' => $client->getResultCode(),
                    'items' => self::normalizeStatsMap($client->getStats('items')),
                    'itemsCode' => $client->getResultCode(),
                ];
            },
        );
    }

    /**
     * @param callable(\Memcached, string): array<string, mixed>      $peclScenario
     * @param callable(MemcachedClient, string): array<string, mixed> $pureScenario
     */
    private function assertParity(callable $peclScenario, callable $pureScenario): void
    {
        $prefix = 'parity_'.bin2hex(random_bytes(6)).'_';

        $pecl = $this->peclClient();
        $pecl->flush();

        $peclResult = $this->normalizeForParity($peclScenario($pecl, $prefix));

        $pure = $this->pureClient();
        $pure->flush();

        $pureResult = $this->normalizeForParity($pureScenario($pure, $prefix));

        self::assertSame($peclResult, $pureResult);
    }

    private function peclClient(): \Memcached
    {
        $client = new \Memcached();
        $client->addServer($this->host(), $this->port());
        $client->setOption(\Memcached::OPT_COMPRESSION, false);
        $client->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP);

        return $client;
    }

    private function pureClient(): MemcachedClient
    {
        $client = new MemcachedClient();
        $client->addServer($this->host(), $this->port());
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_PHP);

        return $client;
    }

    private function normalizeForParity(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if ('cas' === $key) {
                $normalized[$key] = $this->normalizeCas($item);
                continue;
            }

            if (\is_array($item)) {
                $normalized[$key] = $this->normalizeForParity($item);
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * @return array{type:string,zero:bool,present:bool}
     */
    private function normalizeCas(mixed $cas): array
    {
        return [
            'type' => get_debug_type($cas),
            'zero' => 0 === $cas || '0' === $cas,
            'present' => null !== $cas,
        ];
    }

    /**
     * @param array<array-key, mixed>|false $versions
     *
     * @return array<array-key, string>|false
     */
    private static function normalizeVersionMap(array|false $versions): array|false
    {
        if (false === $versions) {
            return false;
        }

        return array_map(static fn (mixed $value): string => \is_string($value) && '' !== $value ? '<version>' : '', $versions);
    }

    /**
     * @param array<array-key, mixed>|false $stats
     *
     * @return array<string, array<string, string>|false>|false
     */
    private static function normalizeStatsMap(array|false $stats): array|false
    {
        if (false === $stats) {
            return false;
        }

        $normalized = [];
        foreach ($stats as $server => $serverStats) {
            $serverKey = (string) $server;
            if (false === $serverStats) {
                $normalized[$serverKey] = false;
                continue;
            }

            self::assertIsArray($serverStats);
            $normalizedServerStats = [];
            foreach ($serverStats as $statKey => $statValue) {
                $normalizedServerStats[(string) $statKey] = \is_string($statValue) ? '<string>' : get_debug_type($statValue);
            }

            $normalized[$serverKey] = $normalizedServerStats;
            ksort($normalized[$serverKey]);
        }

        ksort($normalized);

        return $normalized;
    }
}
