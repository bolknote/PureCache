<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\CacheClient;
use PureCache\Redis\RedisClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class RedisClientApiWireTest extends TestCase
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

    public function testCheckKeyAndServerByKeyOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertFalse($client->checkKey(''));
        self::assertFalse($client->checkKey(str_repeat('k', 70_000)));

        $servers = $client->getServerByKey('route');
        self::assertIsArray($servers);
        self::assertSame('127.0.0.1', $servers['host'] ?? null);
    }

    public function testGetDelayedInvokesValueCallback(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('cb', 'payload'));

        $seen = [];
        self::assertTrue($client->getDelayed(['cb'], false, static function (CacheClient $c, array $item) use (&$seen): void {
            $seen[] = $item;
            self::assertInstanceOf(RedisClient::class, $c);
        }));
        self::assertNotSame([], $seen);
    }

    public function testGetMultiPreserveOrderOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('z', 3));
        self::assertTrue($client->set('a', 1));

        $found = $client->getMulti(['z', 'a'], RedisClient::GET_PRESERVE_ORDER);
        self::assertIsArray($found);
        self::assertSame([3, 1], array_values($found));
    }

    public function testFlushBuffersAfterSetOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->set('buf', 1));
        self::assertTrue($client->flushBuffers());
    }

    public function testPollTimeoutOptionInvalidatesPoolOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setOption(RedisClient::OPT_POLL_TIMEOUT, 250_000));
        self::assertTrue($client->set('after_poll', 1));
        self::assertSame(1, $client->get('after_poll'));
    }

    public function testEncodingKeyRoundTripWhenOpenSslAvailable(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl extension is not available');
        }

        $client = $this->clientOnFakeRedis();
        self::assertTrue($client->setEncodingKey('wire-secret'));
        self::assertTrue($client->set('enc', ['secret' => true]));
        self::assertSame(['secret' => true], $client->get('enc'));
    }

    private function clientOnFakeRedis(): RedisClient
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);
        $this->wireWorkers[] = $process;

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $port);

        return $client;
    }
}
