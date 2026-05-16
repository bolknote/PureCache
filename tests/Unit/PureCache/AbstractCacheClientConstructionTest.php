<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class AbstractCacheClientConstructionTest extends TestCase
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

    public function testConnectionStringBootstrapsServersFromConstructor(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new RedisClient(null, null, '127.0.0.1:'.$port);
        self::assertTrue($client->set('conn-str', 'ok'));
        self::assertSame('ok', $client->get('conn-str'));
    }

    public function testPersistentPoolReusesStateAcrossInstances(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $first = new RedisClient('shared-pool', null, '127.0.0.1:'.$port);
        self::assertTrue($first->set('pooled', 'v'));
        self::assertTrue($first->isPristine());

        $second = new RedisClient('shared-pool');
        self::assertFalse($second->isPristine());
        self::assertSame('v', $second->get('pooled'));
    }

    public function testConstructorCallbackThrowInvalidatesPool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        new RedisClient(null, static function (): never {
            throw new \RuntimeException('boom');
        });
    }

    public function testNonPersistentClientDisconnectsOnDestruct(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new RedisClient(null, null, '127.0.0.1:'.$port);
        self::assertTrue($client->set('tmp', 1));
        unset($client);
    }
}
