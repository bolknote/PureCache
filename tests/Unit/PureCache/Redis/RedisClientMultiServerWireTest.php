<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class RedisClientMultiServerWireTest extends TestCase
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

    public function testSetAndGetRoundTripAcrossTwoFakeRedisServers(): void
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

        self::assertTrue($client->set('shard-a', 'A'));
        self::assertTrue($client->set('shard-b', 'B'));
        self::assertSame('A', $client->get('shard-a'));
        self::assertSame('B', $client->get('shard-b'));

        $versions = $client->getVersion();
        self::assertIsArray($versions);
        self::assertCount(2, $versions);
    }

    public function testUnreachableServerSurfacesFailureMetadata(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(RedisClient::OPT_SERVER_FAILURE_LIMIT, 1);

        self::assertFalse($client->get('probe'));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
        self::assertNotSame('', $client->getResultMessage());
        self::assertNotSame('', $client->getLastErrorMessage());
    }
}
