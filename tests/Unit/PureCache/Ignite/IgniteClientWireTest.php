<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class IgniteClientWireTest extends TestCase
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

    public function testSetAndGetOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();

        self::assertTrue($client->set('wire_key', ['z' => 9], 30));
        self::assertSame(['z' => 9], $client->get('wire_key'));
        self::assertSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testDeleteOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('del_me', 'x'));
        self::assertTrue($client->delete('del_me'));
        self::assertFalse($client->get('del_me'));
        self::assertSame(IgniteClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testAddAndReplaceOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->add('add_key', 'first'));
        self::assertFalse($client->add('add_key', 'second'));
        self::assertSame(IgniteClient::RES_NOTSTORED, $client->getResultCode());
        self::assertTrue($client->replace('add_key', 'replaced'));
        self::assertSame('replaced', $client->get('add_key'));
    }

    public function testGetMultiOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('m1', 1));
        self::assertTrue($client->set('m2', 2));

        $found = $client->getMulti(['m1', 'm2', 'm3']);
        self::assertIsArray($found);
        self::assertSame(['m1' => 1, 'm2' => 2], $found);
    }

    public function testIncrementAndDecrementOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertSame(5, $client->increment('counter', 5, 5, 0));
        self::assertSame(10, $client->increment('counter', 5));
        self::assertSame(7, $client->decrement('counter', 3));
    }

    public function testGetStatsAndVersionOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('stat_key', 1));

        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertArrayHasKey('127.0.0.1:'.$client->getServerList()[0]['port'], $stats);

        $versions = $client->getVersion();
        self::assertIsArray($versions);
        $hostKey = '127.0.0.1:'.$client->getServerList()[0]['port'];
        self::assertIsString($versions[$hostKey] ?? null);
        self::assertStringContainsString('2.17.0', $versions[$hostKey]);
    }

    public function testFlushAndGetAllKeysOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('listed', 'x'));

        $keys = $client->getAllKeys();
        self::assertIsArray($keys);
        self::assertContains('listed', $keys);

        self::assertTrue($client->flush());
        self::assertFalse($client->get('listed'));
    }

    public function testSetMultiOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setMulti(['a' => 1, 'b' => 2], 60));
        self::assertSame(1, $client->get('a'));
        self::assertSame(2, $client->get('b'));
    }

    public function testGetMissReturnsFalseAndNotfound(): void
    {
        $client = $this->clientOnFakeIgnite();

        self::assertFalse($client->get('missing_key'));
        self::assertSame(IgniteClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testCasUpdatesWhenTokenMatches(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('cas_key', 'v1'));
        $extended = $client->get('cas_key', null, IgniteClient::GET_EXTENDED);
        self::assertIsArray($extended);
        self::assertArrayHasKey('cas', $extended);
        $casToken = $extended['cas'];
        if (!\is_string($casToken) && !\is_int($casToken) && !\is_float($casToken)) {
            self::fail('expected cas token to be string|int|float');
        }

        self::assertTrue($client->cas($casToken, 'cas_key', 'v2'));
        self::assertSame('v2', $client->get('cas_key'));
    }

    public function testTouchOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('touch_me', 'v', 30));
        self::assertTrue($client->touch('touch_me', 120));
        self::assertSame('v', $client->get('touch_me'));
    }

    public function testDeleteMultiOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('d1', 1));
        self::assertTrue($client->set('d2', 2));

        $results = $client->deleteMulti(['d1', 'd2', 'missing']);
        self::assertSame([true, true, IgniteClient::RES_NOTFOUND], array_values($results));
    }

    public function testAppendAndPrependOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setOption(IgniteClient::OPT_COMPRESSION, false));
        self::assertTrue($client->set('txt', 'mid'));
        self::assertTrue($client->append('txt', '_end'));
        self::assertTrue($client->prepend('txt', 'start_'));
        self::assertSame('start_mid_end', $client->get('txt'));
    }

    public function testReplaceOnMissingKeyFails(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertFalse($client->replace('ghost', 'v'));
        self::assertSame(IgniteClient::RES_NOTSTORED, $client->getResultCode());
    }

    public function testIncrementWithoutInitialOnMissingKeyFails(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertFalse($client->increment('absent', 1));
        self::assertSame(IgniteClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testGetByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setByKey('shard', 'routed', 'payload'));
        self::assertSame('payload', $client->getByKey('shard', 'routed'));
    }

    public function testIncrementByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertSame(3, $client->incrementByKey('route', 'n', 3, 3, 0));
        self::assertSame(6, $client->incrementByKey('route', 'n', 3));
    }

    public function testDeleteByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setByKey('route', 'gone', 'v'));
        self::assertTrue($client->deleteByKey('route', 'gone'));
        self::assertFalse($client->getByKey('route', 'gone'));
    }

    public function testGetMultiPreservesOrderWhenRequested(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('z', 3));
        self::assertTrue($client->set('a', 1));
        self::assertTrue($client->set('m', 2));

        $found = $client->getMulti(['z', 'a', 'm'], IgniteClient::GET_PRESERVE_ORDER);
        self::assertIsArray($found);
        self::assertSame([3, 1, 2], array_values($found));
    }

    public function testNegativeIncrementOffsetTriggersWarning(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertFalse(@$client->increment('bad', -1));
        self::assertSame(IgniteClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testExpiredEntryIsTreatedAsMissOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('ttl_key', 'v', 1));
        usleep(1_100_000);
        self::assertFalse($client->get('ttl_key'));
        self::assertSame(IgniteClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testCasMismatchReturnsDataExists(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('cas_fail', 'v1'));
        self::assertFalse($client->cas('999999', 'cas_fail', 'v2'));
        self::assertSame(IgniteClient::RES_DATA_EXISTS, $client->getResultCode());
    }

    public function testDecrementByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertSame(10, $client->decrementByKey('route', 'n', 0, 10, 0));
        self::assertSame(7, $client->decrementByKey('route', 'n', 3));
    }

    public function testGetDelayedFetchAllOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('rd1', 1));
        self::assertTrue($client->getDelayed(['rd1']));
        $all = $client->fetchAll();
        self::assertIsArray($all);
        self::assertCount(1, $all);
    }

    public function testSetMultiByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setMultiByKey('route', ['k1' => 1, 'k2' => 2]));
        self::assertSame(1, $client->getByKey('route', 'k1'));
        self::assertSame(2, $client->getByKey('route', 'k2'));
    }

    public function testOversizedFrameOnWireReturnsE2big(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_oversized_frame_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
            'FAKE_IGNITE_FRAME_SIZE' => '200',
        ]);

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $port);
        $client->setOption(IgniteClient::OPT_ITEM_SIZE_LIMIT, 64);

        self::assertFalse($client->get('wire_trap_key'));
        self::assertSame(IgniteClient::RES_E2BIG, $client->getResultCode());
    }

    public function testGetMultiByKeyAndReplaceByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setMultiByKey('route', ['rk' => 'v']));
        self::assertSame(['rk' => 'v'], $client->getMultiByKey('route', ['rk', 'missing']));
        self::assertTrue($client->replaceByKey('route', 'rk', 'v2'));
        self::assertSame('v2', $client->getByKey('route', 'rk'));
    }

    public function testGetStatsSlabsOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('slab', 1));
        self::assertIsArray($client->getStats('slabs'));
    }

    public function testGetStatsItemsTypeOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('item_stats', ['x' => 1]));
        $items = $client->getStats('items');
        self::assertIsArray($items);
        self::assertNotSame([], $items);
    }

    private function clientOnFakeIgnite(): IgniteClient
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
        ]);
        $this->wireWorkers[] = $process;

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $port);

        return $client;
    }
}
