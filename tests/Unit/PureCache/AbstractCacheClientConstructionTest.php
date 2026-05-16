<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;
use PureCache\Redis\RedisClient;
use PureCache\Redis\RedisClientState;
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

        $state = $this->redisState($client);
        self::assertCount(1, $state->redisByServerIndex);
        $native = $state->redisByServerIndex[0];
        self::assertInstanceOf(NativeRedisClient::class, $native);
        self::assertIsResource($this->nativeStream($native));

        unset($client);
        gc_collect_cycles();

        self::assertSame([], $state->redisByServerIndex);
        self::assertNull($this->nativeStream($native));

        $replacement = new RedisClient(null, null, '127.0.0.1:'.$port);
        self::assertTrue($replacement->set('after-destruct', 'ok'));
        self::assertSame('ok', $replacement->get('after-destruct'));
    }

    private function redisState(RedisClient $client): RedisClientState
    {
        $method = new \ReflectionMethod(RedisClient::class, 'state');
        $state = $method->invoke($client);
        if (!$state instanceof RedisClientState) {
            throw new \LogicException('state() must return RedisClientState');
        }

        return $state;
    }

    private function nativeStream(NativeRedisClient $native): mixed
    {
        $property = new \ReflectionProperty(NativeRedisClient::class, 'stream');

        return $property->getValue($native);
    }
}
