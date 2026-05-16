<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class RedisClientReplicaWireTest extends TestCase
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

    public function testSetMultiFansOutToPrimaryAndReplica(): void
    {
        $portA = $this->reserveEphemeralPort();
        $portB = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $portA,
        ]);
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $portB,
        ]);

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $portA);
        $client->addServer('127.0.0.1', $portB);
        $client->setOption(RedisClient::OPT_NUMBER_OF_REPLICAS, 1);
        $client->setOption(RedisClient::OPT_LIBKETAMA_COMPATIBLE, true);

        self::assertTrue($client->setMulti(['fanout-a' => 1, 'fanout-b' => 2], 60));
        self::assertSame(1, $client->get('fanout-a'));
        self::assertSame(2, $client->get('fanout-b'));
    }

    public function testGetDelayedFetchBatchAcrossServers(): void
    {
        $portA = $this->reserveEphemeralPort();
        $portB = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $portA,
        ]);
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $portB,
        ]);

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $portA);
        $client->addServer('127.0.0.1', $portB);

        self::assertTrue($client->set('delayed-a', 'A'));
        self::assertTrue($client->set('delayed-b', 'B'));
        self::assertTrue($client->getDelayed(['delayed-a', 'delayed-b']));
        $rows = $client->fetchAll();
        self::assertIsArray($rows);
        self::assertGreaterThanOrEqual(2, \count($rows));
    }
}
