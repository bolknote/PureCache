<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class ClientDelayedFetchCoordinatorRedisWireTest extends TestCase
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

    public function testFetchAllDrainsRedisDelayedQueue(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->set('rd', 'v'));

        $registry = $this->registry($client);
        $coordinator = $registry->delayedFetch();
        self::assertTrue($coordinator->enqueueDelayed(null, ['rd'], false, null));

        $all = $coordinator->fetchAll();
        self::assertIsArray($all);
        self::assertCount(1, $all);
        self::assertFalse($coordinator->fetchOne());
        self::assertSame(RedisClient::RES_END, $client->getResultCode());
    }

    public function testEnqueueRejectsInvalidServerKey(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);

        $coordinator = $this->registry($client)->delayedFetch();
        self::assertFalse($coordinator->enqueueDelayed('', ['k'], false, null));
        self::assertSame(RedisClient::RES_BAD_KEY_PROVIDED, $client->getResultCode());
    }

    private function registry(RedisClient $client): ClientCoordinatorRegistry
    {
        $method = new \ReflectionMethod($client, 'coordinators');
        $registry = $method->invoke($client);
        if (!$registry instanceof ClientCoordinatorRegistry) {
            throw new \LogicException('expected ClientCoordinatorRegistry');
        }

        return $registry;
    }
}
