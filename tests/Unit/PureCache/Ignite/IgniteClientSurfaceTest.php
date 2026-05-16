<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Ignite\IgniteClientState;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class IgniteClientSurfaceTest extends TestCase
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

    public function testDefaultPortAndMaxKeyLength(): void
    {
        $client = new IgniteClient();
        $port = new \ReflectionMethod(IgniteClient::class, 'defaultPort');
        self::assertSame(IgniteProtocol::DEFAULT_PORT, $port->invoke($client));
        self::assertSame(65_536, $client->maxKeyLength());
    }

    public function testUnsupportedOptionMessageMentionsIgnite(): void
    {
        $client = new IgniteClient();
        self::assertStringContainsString('Ignite', $client->unsupportedOptionMessage());
        self::assertFalse($client->isUnsupportedOption(IgniteClient::OPT_PREFIX_KEY));
    }

    public function testOnPoolInvalidatedDisconnectsTrackedClients(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
        ]);

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('pool', 1));
        $client->onPoolInvalidated();
        $state = (new \ReflectionProperty($client, 'core'))->getValue($client);
        self::assertInstanceOf(IgniteClientState::class, $state);
        self::assertSame([], $state->clientByServerIndex);
        self::assertSame([], $state->cacheIdByServerIndex);
    }

    public function testFetchAllFailureOnClosedPort(): void
    {
        $client = new IgniteClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(IgniteClient::OPT_CONNECT_TIMEOUT, 50);
        self::assertTrue($client->getDelayed(['k']));
        self::assertFalse($client->fetchAll());
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testGetMultiFailureOnClosedPort(): void
    {
        $client = new IgniteClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(IgniteClient::OPT_CONNECT_TIMEOUT, 50);
        self::assertFalse($client->getMulti(['k']));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }
}
