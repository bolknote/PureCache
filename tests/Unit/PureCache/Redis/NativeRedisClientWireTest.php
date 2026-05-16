<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;
use PureCache\Redis\RedisItemScripts;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class NativeRedisClientWireTest extends TestCase
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

    public function testConnectPipelineAndLowLevelCommandsOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();

        $replies = $client->pipeline([
            ['DBSIZE'],
            ['INFO'],
        ]);
        self::assertCount(2, $replies);
        self::assertIsInt($replies[0]);
        self::assertIsString($replies[1]);

        self::assertSame(0, $client->dbsize());
        $client->executeRaw(['HGETALL', 'missing']);
        self::assertSame([], $client->hgetall('missing'));

        $client->disconnect();
        self::assertNull((new \ReflectionProperty($client, 'stream'))->getValue($client));
    }

    public function testDelFlushdbAndScanOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        $client->evalScript(RedisItemScripts::LUA_ADD, ['seed'], ['payload', '0', '0']);

        self::assertSame(1, $client->del(['seed']));
        self::assertSame(0, $client->dbsize());

        [, $keys] = $client->scan(0, ['MATCH' => '*', 'COUNT' => 10]);
        self::assertSame([], $keys);

        $client->flushdb();
        self::assertSame(0, $client->dbsize());
    }

    public function testEvalScriptLoadsOnNoscript(): void
    {
        $client = $this->clientOnFakeRedis();
        $result = $client->evalScript(RedisItemScripts::LUA_CAS_SET, ['k'], ['v', '0', '0', '']);
        self::assertIsArray($result);
        self::assertSame(1, $result[0] ?? null);
    }

    public function testInfoAndObjectOnFakeRedis(): void
    {
        $client = $this->clientOnFakeRedis();
        $client->evalScript(RedisItemScripts::LUA_ADD, ['obj'], ['v', '0', '0']);

        $info = $client->info('server');
        self::assertNotSame([], $info);

        self::assertSame('embstr', $client->object('encoding', 'obj'));
    }

    public function testPipelineCapturesPerCommandFailures(): void
    {
        $client = $this->clientOnFakeRedis();
        $replies = $client->pipeline([
            ['PING'],
            ['EVALSHA', 'deadbeef', '0'],
        ]);
        self::assertCount(2, $replies);
        self::assertSame('PONG', $replies[0]);
        self::assertNotSame('PONG', $replies[1]);
    }

    public function testEmptyPipelineReturnsNoReplies(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertSame([], $client->pipeline([]));
    }

    public function testDelWithNoKeysReturnsZero(): void
    {
        $client = $this->clientOnFakeRedis();
        self::assertSame(0, $client->del([]));
    }

    public function testScanRejectsInvalidCount(): void
    {
        $client = $this->clientOnFakeRedis();
        $this->expectException(\InvalidArgumentException::class);
        $client->scan(0, ['COUNT' => 0]);
    }

    public function testAuthAndSelectHandshakeOnFakeRedis(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new NativeRedisClient('127.0.0.1', $port, 2.0, 'user', 'secret', 1);
        $client->connect();
        self::assertSame('PONG', $client->executeRaw(['PING']));
        $client->disconnect();
    }

    private function clientOnFakeRedis(): NativeRedisClient
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_redis_memcached_shape_server.php', [
            'FAKE_REDIS_PORT' => (string) $port,
        ]);

        $client = new NativeRedisClient('127.0.0.1', $port, 2.0);
        $client->connect();

        return $client;
    }
}
