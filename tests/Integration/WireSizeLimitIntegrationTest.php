<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;
use PureCache\Redis\RedisClient;

/**
 * Wire-level read-limit tests using fake TCP peers (no live memcached/redis/ignite).
 */
final class WireSizeLimitIntegrationTest extends TestCase
{
    use WireSizeLimitIntegrationTrait;

    public function testMemcachedWireOversizedVaIsRejected(): void
    {
        $this->assertWireOversizedVaIsRejected();
    }

    public function testRedisWireOversizedBulkIsRejected(): void
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker(
            __DIR__.'/workers/fake_redis_oversized_bulk_server.php',
            [
                'FAKE_REDIS_PORT' => (string) $port,
                'FAKE_REDIS_BULK_SIZE' => '200',
            ],
        );

        $client = new RedisClient();
        $client->addServer('127.0.0.1', $port);
        $client->setOption(RedisClient::OPT_ITEM_SIZE_LIMIT, 64);

        self::assertFalse($client->get('wire_trap_key'));
        self::assertSame(RedisClient::RES_E2BIG, $client->getResultCode());

        proc_terminate($process, 9);
        proc_close($process);
    }

    public function testIgniteWireOversizedFrameIsRejected(): void
    {
        $port = $this->reserveEphemeralPort();
        $process = $this->startFakeWireWorker(
            __DIR__.'/workers/fake_ignite_oversized_frame_server.php',
            [
                'FAKE_IGNITE_PORT' => (string) $port,
                'FAKE_IGNITE_FRAME_SIZE' => '200',
            ],
        );

        $client = new IgniteClient();
        $client->addServer('127.0.0.1', $port);
        self::assertTrue($client->setOption(IgniteClient::OPT_ITEM_SIZE_LIMIT, 64));
        self::assertSame(64, $client->getOption(IgniteClient::OPT_ITEM_SIZE_LIMIT));

        self::assertFalse($client->get('wire_trap_key'));
        self::assertSame(
            IgniteClient::RES_E2BIG,
            $client->getResultCode(),
            $client->getResultMessage(),
        );

        proc_terminate($process, 9);
        proc_close($process);
    }
}
