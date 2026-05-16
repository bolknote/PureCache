<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class IgniteClientMultiServerWireTest extends TestCase
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

    public function testOperationsAcrossTwoFakeIgniteServers(): void
    {
        $portA = $this->reserveEphemeralPort();
        $portB = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $portA,
        ]);
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $portB,
        ]);

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $portA);
        $client->addServer('127.0.0.1', $portB);

        self::assertTrue($client->set('i-a', 1));
        self::assertTrue($client->set('i-b', 2));
        self::assertSame(1, $client->get('i-a'));
        self::assertSame(2, $client->get('i-b'));

        $stats = $client->getStats('items');
        self::assertIsArray($stats);
        self::assertCount(2, $stats);
    }
}
