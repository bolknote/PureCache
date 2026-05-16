<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class RedisClientWireTest extends TestCase
{
    use FakeWireWorkerTrait;

    /** @var list<resource> */
    private array $wireWorkers = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->wireWorkers as $process) {
            $this->stopFakeWireWorker($process);
        }

        $this->wireWorkers = [];
        parent::tearDown();
    }

    public function testSetAndGetRoundTripOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();

        self::assertTrue($client->set('alpha', ['n' => 1], 60));
        self::assertSame(['n' => 1], $client->get('alpha'));
        self::assertSame(RedisClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testGetMissReturnsFalseAndNotfound(): void
    {
        $client = $this->clientOnFakeRedis();

        self::assertFalse($client->get('missing_key'));
        self::assertSame(RedisClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testDeleteRemovesKey(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('gone', 'v'));
        self::assertTrue($client->delete('gone'));
        self::assertFalse($client->get('gone'));
        self::assertSame(RedisClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testAddRefusesExistingKey(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('dup', 'first'));
        self::assertFalse($client->add('dup', 'second'));
        self::assertSame(RedisClient::RES_NOTSTORED, $client->getResultCode());
        self::assertSame('first', $client->get('dup'));
    }

    public function testGetMultiReturnsSubset(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('m1', 1));
        self::assertTrue($client->set('m2', 2));

        $found = $client->getMulti(['m1', 'm2', 'm3']);
        self::assertIsArray($found);
        self::assertSame(['m1' => 1, 'm2' => 2], $found);
    }

    public function testIncrementOnMissingKeyWithInitial(): void
    {
        $client = $this->clientOnFakeRedis();

        self::assertSame(5, $client->increment('counter', 5, 5, 0));
        self::assertSame(10, $client->increment('counter', 5));
        self::assertSame(RedisClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testCasUpdatesWhenTokenMatches(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('cas_key', 'v1'));
        $extended = $client->get('cas_key', null, RedisClient::GET_EXTENDED);
        self::assertIsArray($extended);
        self::assertArrayHasKey('cas', $extended);
        $casToken = $extended['cas'];
        if (!\is_string($casToken) && !\is_int($casToken) && !\is_float($casToken)) {
            self::fail('expected cas token to be string|int|float');
        }

        self::assertTrue($client->cas($casToken, 'cas_key', 'v2'));
        self::assertSame('v2', $client->get('cas_key'));
    }

    public function testReplaceUpdatesExistingKey(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('rep', 'old'));
        self::assertTrue($client->replace('rep', 'new'));
        self::assertSame('new', $client->get('rep'));
    }

    public function testGetStatsAndVersionOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('stat_key', 1));

        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertArrayHasKey('127.0.0.1:'.$client->getServerList()[0]['port'], $stats);

        $versions = $client->getVersion();
        self::assertIsArray($versions);
        self::assertNotSame('', $versions['127.0.0.1:'.$client->getServerList()[0]['port']] ?? '');
    }

    public function testFlushAndGetAllKeysOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('listed', 'x'));

        $keys = $client->getAllKeys();
        self::assertIsArray($keys);
        self::assertContains('listed', $keys);

        self::assertTrue($client->flush());
        self::assertFalse($client->get('listed'));
    }

    public function testDeleteMultiReturnsPerKeyResults(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('d1', 1));
        self::assertTrue($client->set('d2', 2));

        $results = $client->deleteMulti(['d1', 'd2', 'missing']);
        self::assertSame([true, true, RedisClient::RES_NOTFOUND], array_values($results));
    }

    public function testSetMultiAndTouchOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setMulti(['a' => 1, 'b' => 2], 60));
        self::assertSame(1, $client->get('a'));
        self::assertTrue($client->touch('a', 120));
    }

    public function testDecrementOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertSame(10, $client->decrement('n', 0, 10, 0));
        self::assertSame(7, $client->decrement('n', 3));
    }

    public function testGetStatsItemsTypeOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('item_stats', ['x' => 1]));
        $items = $client->getStats('items');
        self::assertIsArray($items);
        self::assertNotSame([], $items);
    }

    public function testGetByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setByKey('route', 'key', 'val'));
        self::assertSame('val', $client->getByKey('route', 'key'));
    }

    public function testAppendAndPrependOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setOption(RedisClient::OPT_COMPRESSION, false));
        self::assertTrue($client->set('txt', 'mid'));
        self::assertTrue($client->setByKey('route', 'bykey', 'mid'));
        self::assertTrue($client->append('txt', '_end'));
        self::assertTrue($client->prepend('txt', 'start_'));
        self::assertTrue($client->appendByKey('route', 'bykey', '_tail'));
        self::assertTrue($client->prependByKey('route', 'bykey', 'head_'));
        self::assertSame('start_mid_end', $client->get('txt'));
        self::assertSame('head_mid_tail', $client->getByKey('route', 'bykey'));
    }

    public function testGetDelayedFetchAllOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('rd1', 1));
        self::assertTrue($client->getDelayed(['rd1']));
        $all = $client->fetchAll();
        self::assertIsArray($all);
        self::assertCount(1, $all);
    }

    public function testCasMismatchReturnsDataExists(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('cas_fail', 'v1'));
        self::assertFalse($client->cas('999999', 'cas_fail', 'v2'));
        self::assertSame(RedisClient::RES_DATA_EXISTS, $client->getResultCode());
    }

    public function testReplaceOnMissingKeyFailsOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertFalse($client->replace('ghost', 'v'));
        self::assertSame(RedisClient::RES_NOTSTORED, $client->getResultCode());
    }

    public function testIncrementByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertSame(4, $client->incrementByKey('route', 'n', 4, 4, 0));
        self::assertSame(9, $client->incrementByKey('route', 'n', 5));
    }

    public function testDecrementByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertSame(10, $client->decrementByKey('route', 'n', 0, 10, 0));
        self::assertSame(7, $client->decrementByKey('route', 'n', 3));
    }

    public function testGetMultiByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setMultiByKey('route', ['k1' => 1, 'k2' => 2]));
        $found = $client->getMultiByKey('route', ['k1', 'k2', 'k3']);
        self::assertIsArray($found);
        self::assertSame(['k1' => 1, 'k2' => 2], $found);
    }

    public function testAddAndReplaceByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->addByKey('route', 'fresh', 'v'));
        self::assertFalse($client->addByKey('route', 'fresh', 'v2'));
        self::assertTrue($client->replaceByKey('route', 'fresh', 'v3'));
        self::assertSame('v3', $client->getByKey('route', 'fresh'));
    }

    public function testFetchReturnsSingleEntryAfterGetDelayed(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('rd_fetch', 'payload'));
        self::assertTrue($client->getDelayed(['rd_fetch']));
        $row = $client->fetch();
        self::assertIsArray($row);
        self::assertSame('rd_fetch', $row['key'] ?? null);
        self::assertSame('payload', $row['value'] ?? null);
    }

    public function testDeleteByKeyRejectsInvalidServerKey(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertFalse($client->deleteByKey('', 'key'));
        self::assertSame(RedisClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
    }

    public function testTouchByKeyAndDeleteMultiByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setByKey('route', 'touch', 'v', 30));
        self::assertTrue($client->touchByKey('route', 'touch', 120));
        self::assertTrue($client->setByKey('route', 'd1', 1));
        self::assertTrue($client->setByKey('route', 'd2', 2));
        $results = $client->deleteMultiByKey('route', ['d1', 'd2', 'ghost']);
        self::assertSame([true, true, RedisClient::RES_NOTFOUND], array_values($results));
    }

    public function testOversizedBulkOnWireReturnsE2big(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_oversized_bulk_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
            'FAKE_REDIS_BULK_SIZE' => '200',
        ]);

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $port);
        $client->setOption(RedisClient::OPT_ITEM_SIZE_LIMIT, 64);

        self::assertFalse($client->get('wire_trap_key'));
        self::assertSame(RedisClient::RES_E2BIG, $client->getResultCode());
    }

    public function testSetOptionsAndResetServerListOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setOptions([
            RedisClient::OPT_PREFIX_KEY => 'pfx:',
            RedisClient::OPT_SERIALIZER => RedisClient::SERIALIZER_PHP,
        ]));
        self::assertSame('pfx:', $client->getOption(RedisClient::OPT_PREFIX_KEY));
        $port = $client->getServerList()[0]['port'];
        self::assertTrue($client->resetServerList());
        self::assertTrue($client->addServer('127.0.0.1', $port));
        self::assertTrue($client->set('after-reset', 1));
    }

    public function testQuitAndIsPersistentOnFakeRedis(): void
    {
        $client = new RedisClient('wire-persist');
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->isPersistent());
        self::assertTrue($client->set('q', 1));
        self::assertTrue($client->quit());
    }

    public function testGetStatsSlabsAndSizesTypesOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('slab_key', 'v'));

        self::assertIsArray($client->getStats('slabs'));
        $sizes = $client->getStats('sizes');
        self::assertIsArray($sizes);
        self::assertArrayHasKey('127.0.0.1:'.$client->getServerList()[0]['port'], $sizes);
    }

    public function testCasByKeyAndGetDelayedByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setByKey('route', 'cas-k', 'v1'));
        $extended = $client->getByKey('route', 'cas-k', null, RedisClient::GET_EXTENDED);
        self::assertIsArray($extended);
        $token = $extended['cas'] ?? null;
        self::assertTrue(\is_string($token) || \is_int($token) || \is_float($token));
        self::assertTrue($client->casByKey($token, 'route', 'cas-k', 'v2'));
        self::assertTrue($client->getDelayedByKey('route', ['cas-k']));
        self::assertIsArray($client->fetchAll());
    }

    public function testAddServerViaRedisUrlOnFakeRedis(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new RedisClient();
        self::assertTrue($client->addServer('redis://127.0.0.1:'.$port.'/0'));
        self::assertTrue($client->set('url_key', 'v'));
        self::assertSame('v', $client->get('url_key'));
    }

    private function clientOnFakeRedis(): RedisClient
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);
        $this->wireWorkers[] = $process;

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $port);

        return $client;
    }
}
