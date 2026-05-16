<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class IgniteClientApiWireTest extends TestCase
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

    public function testCheckKeyAndGetServerByKeyOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertFalse($client->checkKey(''));
        $server = $client->getServerByKey('shard');
        self::assertIsArray($server);
        self::assertSame('127.0.0.1', $server['host'] ?? null);
    }

    public function testGetMultiByKeyPreserveOrderOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->setByKey('route', 'z', 3));
        self::assertTrue($client->setByKey('route', 'a', 1));

        $found = $client->getMultiByKey('route', ['z', 'a'], IgniteClient::GET_PRESERVE_ORDER);
        self::assertIsArray($found);
        self::assertSame([3, 1], array_values($found));
    }

    public function testFlushBuffersAndQuitOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('q', 1));
        self::assertTrue($client->flushBuffers());
        self::assertTrue($client->quit());
    }

    public function testGetStatsGeneralOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        self::assertTrue($client->set('stats_probe', 1));
        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertNotSame([], $stats);
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
