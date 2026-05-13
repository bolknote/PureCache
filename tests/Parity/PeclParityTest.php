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
        foreach ([
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
            'OPT_USER_FLAGS',
            'SERIALIZER_PHP',
        ] as $constant) {
            self::assertSame(\Memcached::{$constant}, MemcachedClient::{$constant}, $constant);
        }
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
     * @param array<string, mixed>|false $versions
     *
     * @return array<string, string>|false
     */
    private static function normalizeVersionMap(array|false $versions): array|false
    {
        if (false === $versions) {
            return false;
        }

        return array_map(static fn (mixed $value): string => \is_string($value) && '' !== $value ? '<version>' : '', $versions);
    }

    /**
     * @param array<string, mixed>|false $stats
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
            if (false === $serverStats) {
                $normalized[$server] = false;
                continue;
            }

            self::assertIsArray($serverStats);
            $normalizedServerStats = [];
            foreach ($serverStats as $statKey => $statValue) {
                $normalizedServerStats[(string) $statKey] = \is_string($statValue) ? '<string>' : get_debug_type($statValue);
            }

            $normalized[$server] = $normalizedServerStats;
            ksort($normalized[$server]);
        }

        ksort($normalized);

        return $normalized;
    }
}
