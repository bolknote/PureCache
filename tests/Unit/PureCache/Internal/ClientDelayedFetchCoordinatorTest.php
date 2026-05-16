<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Memcached\MemcachedClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class ClientDelayedFetchCoordinatorTest extends TestCase
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

    public function testFetchWithoutPrimingReturnsNotfinished(): void
    {
        $client = new MemcachedClient();
        $coordinator = $this->registry($client)->delayedFetch();

        self::assertFalse($coordinator->fetchOne());
        self::assertSame(MemcachedClient::RES_FETCH_NOTFINISHED, $client->getResultCode());
    }

    public function testEnqueueRejectsOverlongKey(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);

        $coordinator = $this->registry($client)->delayedFetch();

        self::assertFalse($coordinator->enqueueDelayed(null, [str_repeat('k', 300)], false, null));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
    }

    public function testFetchReturnsEndAfterLastRow(): void
    {
        $client = new MemcachedClient();
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('solo', 1));

        $coordinator = $this->registry($client)->delayedFetch();
        self::assertTrue($coordinator->enqueueDelayed(null, ['solo'], false, null));

        self::assertIsArray($coordinator->fetchOne());
        self::assertFalse($coordinator->fetchOne());
        self::assertSame(MemcachedClient::RES_END, $client->getResultCode());
    }

    public function testFetchAllDrainsMultipleDelayedBatches(): void
    {
        $client = new MemcachedClient();
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('a', 1));
        self::assertTrue($client->set('b', 2));

        $coordinator = $this->registry($client)->delayedFetch();
        self::assertTrue($coordinator->enqueueDelayed(null, ['a'], false, null));
        self::assertTrue($coordinator->enqueueDelayed(null, ['b'], false, null));

        $all = $coordinator->fetchAll();
        self::assertIsArray($all);
        self::assertCount(2, $all);
    }

    public function testDelayedFetchRoundTripOnFakeMeta(): void
    {
        $client = new MemcachedClient();
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('late', 'value'));

        $coordinator = $this->registry($client)->delayedFetch();
        self::assertTrue($coordinator->enqueueDelayed(null, ['late'], false, null));

        $row = $coordinator->fetchOne();
        self::assertIsArray($row);
        self::assertSame('late', $row['key']);
        self::assertSame('value', $row['value']);
    }

    private function registry(MemcachedClient $client): ClientCoordinatorRegistry
    {
        $method = new \ReflectionMethod($client, 'coordinators');
        $registry = $method->invoke($client);
        if (!$registry instanceof ClientCoordinatorRegistry) {
            throw new \LogicException('coordinators() must return ClientCoordinatorRegistry');
        }

        return $registry;
    }
}
