<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class MemcachedReplicaWireTest extends TestCase
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

    public function testWriteFanoutHitsAllFakeMetaServersWhenReplicasEnabled(): void
    {
        $ports = [];
        for ($i = 0; $i < 3; ++$i) {
            $port = $this->reserveEphemeralPort();
            $ports[] = $port;
            $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
                'FAKE_META_PORT' => (string) $port,
            ]);
        }

        $client = new MemcachedClient();
        foreach ($ports as $port) {
            $client->addServer('127.0.0.1', $port);
        }

        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 2);
        self::assertTrue($client->set('fanout-key', 'payload', 60));
        self::assertSame('payload', $client->get('fanout-key'));

        self::assertTrue($client->setMulti(['a' => 1, 'b' => 2]));
        $found = $client->getMulti(['a', 'b']);
        self::assertSame(['a' => 1, 'b' => 2], $found);
    }
}
