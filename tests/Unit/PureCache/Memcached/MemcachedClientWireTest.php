<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class MemcachedClientWireTest extends TestCase
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

    public function testSetAndGetOnFakeMetaServer(): void
    {
        $client = $this->clientOnFakeMeta();

        self::assertTrue($client->set('wire_key', ['z' => 9], 30));
        self::assertSame(['z' => 9], $client->get('wire_key'));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testDeleteOnFakeMetaServer(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('del_me', 'x'));
        self::assertTrue($client->delete('del_me'));
        self::assertFalse($client->get('del_me'));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testGetMissReturnsFalseAndNotfound(): void
    {
        $client = $this->clientOnFakeMeta();

        self::assertFalse($client->get('missing_key'));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testAddRefusesExistingKey(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('dup', 'first'));
        self::assertFalse($client->add('dup', 'second'));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());
        self::assertSame('first', $client->get('dup'));
    }

    public function testReplaceUpdatesExistingKey(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('rep', 'old'));
        self::assertTrue($client->replace('rep', 'new'));
        self::assertSame('new', $client->get('rep'));
    }

    public function testReplaceOnMissingKeyFails(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertFalse($client->replace('ghost', 'v'));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testGetMultiReturnsSubset(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('m1', 1));
        self::assertTrue($client->set('m2', 2));

        $found = $client->getMulti(['m1', 'm2', 'm3']);
        self::assertIsArray($found);
        self::assertSame(['m1' => 1, 'm2' => 2], $found);
    }

    public function testIncrementOnMissingKeyWithInitial(): void
    {
        $client = $this->clientOnFakeMeta();

        self::assertSame(5, $client->increment('counter', 5, 5, 0));
        self::assertSame(10, $client->increment('counter', 5));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testIncrementOnMissingKeyWithoutInitialReturnsNotfound(): void
    {
        $client = $this->clientOnFakeMeta();

        self::assertFalse($client->increment('absent', 1));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testDecrementOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertSame(10, $client->decrement('n', 0, 10, 0));
        self::assertSame(7, $client->decrement('n', 3));
    }

    public function testSetMultiOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->setMulti(['a' => 1, 'b' => 2], 60));
        self::assertSame(1, $client->get('a'));
        self::assertSame(2, $client->get('b'));
    }

    public function testDeleteMultiReturnsPerKeyResults(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('d1', 1));
        self::assertTrue($client->set('d2', 2));

        $results = $client->deleteMulti(['d1', 'd2', 'missing']);
        self::assertSame([true, true, MemcachedClient::RES_NOTFOUND], array_values($results));
    }

    public function testGetStatsAndVersionOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('stat_key', 1));

        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertArrayHasKey('127.0.0.1:'.$client->getServerList()[0]['port'], $stats);

        $versions = $client->getVersion();
        self::assertIsArray($versions);
        $hostKey = '127.0.0.1:'.$client->getServerList()[0]['port'];
        self::assertIsString($versions[$hostKey] ?? null);
        self::assertStringContainsString('1.6.22', $versions[$hostKey]);
    }

    public function testFlushAndGetAllKeysOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('listed', 'x'));

        $keys = $client->getAllKeys();
        self::assertIsArray($keys);
        self::assertContains('listed', $keys);

        self::assertTrue($client->flush());
        self::assertFalse($client->get('listed'));
    }

    public function testTouchOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('touch_me', 'v', 30));
        self::assertTrue($client->touch('touch_me', 120));
        self::assertSame('v', $client->get('touch_me'));
    }

    public function testIncrementByKeyOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertSame(4, $client->incrementByKey('route', 'n', 4, 4, 0));
        self::assertSame(9, $client->incrementByKey('route', 'n', 5));
    }

    public function testDeleteByKeyOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->setByKey('route', 'gone', 'v'));
        self::assertTrue($client->deleteByKey('route', 'gone'));
        self::assertFalse($client->getByKey('route', 'gone'));
    }

    public function testEmptyGetDelayedQueueSucceeds(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->getDelayed([]));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testGetDelayedFetchAndFetchAllOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('d1', 1));
        self::assertTrue($client->set('d2', 2));

        self::assertTrue($client->getDelayed(['d1', 'd2', 'missing']));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());

        $first = $client->fetch();
        self::assertIsArray($first);
        self::assertArrayHasKey('key', $first);
        self::assertArrayHasKey('value', $first);

        $all = $client->fetchAll();
        self::assertIsArray($all);
        self::assertGreaterThanOrEqual(1, \count($all));
    }

    public function testGetExtendedReturnsCasOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('ext', 'payload'));
        $extended = $client->get('ext', null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($extended);
        self::assertSame('payload', $extended['value'] ?? null);
        self::assertArrayHasKey('cas', $extended);
    }

    public function testSetMultiByKeyAndGetMultiByKeyOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->setMultiByKey('route', ['k1' => 1, 'k2' => 2]));
        $found = $client->getMultiByKey('route', ['k1', 'k2', 'ghost']);
        self::assertIsArray($found);
        self::assertSame(['k1' => 1, 'k2' => 2], $found);
    }

    public function testAddReplaceByKeyOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->addByKey('route', 'fresh', 'v'));
        self::assertFalse($client->addByKey('route', 'fresh', 'v2'));
        self::assertTrue($client->replaceByKey('route', 'fresh', 'v3'));
        self::assertSame('v3', $client->getByKey('route', 'fresh'));
    }

    public function testOversizedVaOnWireReturnsE2big(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_oversized_va_server.php', [
            'FAKE_META_PORT' => (string) $port,
            'FAKE_META_VA_SIZE' => '200',
        ]);

        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', $port);
        $client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 64);

        self::assertFalse($client->get('wire_trap_key'));
        self::assertSame(MemcachedClient::RES_E2BIG, $client->getResultCode());
    }

    public function testGetStatsSlabsTypeOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('slab_key', 'v'));
        self::assertIsArray($client->getStats('slabs'));
    }

    public function testGetAllKeysUsesCachedumpOnLegacyFakeMeta(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
            'FAKE_META_VERSION' => '1.4.0-fake',
        ]);

        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('listed_legacy', 'v'));

        $keys = $client->getAllKeys();
        self::assertIsArray($keys);
        self::assertContains('listed_legacy', $keys);
    }

    public function testByKeyMultiOperationsOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->setOption(MemcachedClient::OPT_COMPRESSION, false));
        self::assertTrue($client->setMultiByKey('route', ['x' => 1, 'y' => 2]));
        $found = $client->getMultiByKey('route', ['x', 'y']);
        self::assertSame(['x' => 1, 'y' => 2], $found);
        self::assertTrue($client->deleteByKey('route', 'y'));
    }

    public function testAppendAndPrependOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->setOption(MemcachedClient::OPT_COMPRESSION, false));
        self::assertTrue($client->set('txt', 'mid'));
        self::assertTrue($client->append('txt', '_end'));
        self::assertTrue($client->prepend('txt', 'start_'));
        self::assertSame('start_mid_end', $client->get('txt'));
    }

    public function testGetDelayedWithCasFlagOnFakeMeta(): void
    {
        $client = $this->clientOnFakeMeta();
        self::assertTrue($client->set('cas-delay', 'v'));
        self::assertTrue($client->getDelayed(['cas-delay'], true));
        $row = $client->fetch();
        self::assertIsArray($row);
        self::assertArrayHasKey('cas', $row);
    }

    private function clientOnFakeMeta(): MemcachedClient
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $this->wireWorkers[] = $process;

        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', $port);

        return $client;
    }
}
