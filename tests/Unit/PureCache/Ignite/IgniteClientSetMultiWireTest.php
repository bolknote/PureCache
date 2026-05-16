<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class IgniteClientSetMultiWireTest extends TestCase
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

    public function testSetMultiContinuesAfterOversizedValueOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        $client->setOption(IgniteClient::OPT_ITEM_SIZE_LIMIT, 20);

        self::assertFalse($client->setMulti([
            'ok-a' => 'small-a',
            'big' => str_repeat('x', 64),
            'ok-b' => 'small-b',
        ], 60));
        self::assertSame(IgniteClient::RES_SOME_ERRORS, $client->getResultCode());
        self::assertSame('small-a', $client->get('ok-a'));
        self::assertFalse($client->get('big'));
        self::assertSame('small-b', $client->get('ok-b'));
    }

    private function clientOnFakeIgnite(): IgniteClient
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
        ]);

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $port);

        return $client;
    }
}
