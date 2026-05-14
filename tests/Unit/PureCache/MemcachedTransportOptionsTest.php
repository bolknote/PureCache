<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\ConnectionManager;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;

/**
 * Wire-free coverage that the memcached-only transport tuning options
 * ({@code OPT_CORK}, {@code OPT_POLL_TIMEOUT}, {@code OPT_IO_BYTES_WATERMARK},
 * {@code OPT_IO_MSG_WATERMARK}, {@code OPT_IO_KEY_PREFETCH}) actually reach
 * the {@see ConnectionManager} / {@see \PureCache\Memcached\Internal\StreamConnection}
 * pair after a {@code setOption()}.
 *
 * We can't open real sockets in unit tests, but we can verify the
 * constructor values via reflection — that catches the most likely
 * regression (swapped arg order, dropped arg) without needing a real
 * memcached server.
 */
final class MemcachedTransportOptionsTest extends TestCase
{
    public function testConnectionManagerExposesIoWatermarkSetters(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        $client->setOption(MemcachedClient::OPT_IO_BYTES_WATERMARK, 65536);
        $client->setOption(MemcachedClient::OPT_IO_MSG_WATERMARK, 32);
        $client->setOption(MemcachedClient::OPT_IO_KEY_PREFETCH, 8);

        $manager = $this->connectionManagerOf($client);
        self::assertSame(65536, $manager->ioBytesWatermark());
        self::assertSame(32, $manager->ioMsgWatermark());
        self::assertSame(8, $manager->ioKeyPrefetch());
    }

    public function testPollTimeoutOptionReachesConnectionManager(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        $client->setOption(MemcachedClient::OPT_POLL_TIMEOUT, 2500);

        $manager = $this->connectionManagerOf($client);
        $reflection = new \ReflectionProperty($manager, 'pollTimeoutMs');
        self::assertSame(2500, $reflection->getValue($manager));
    }

    public function testCorkOptionReachesConnectionManager(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        $client->setOption(MemcachedClient::OPT_CORK, true);

        $manager = $this->connectionManagerOf($client);
        $reflection = new \ReflectionProperty($manager, 'tcpCork');
        self::assertTrue($reflection->getValue($manager));
    }

    public function testTransportOptionsArePropagatedAfterPoolRebuild(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);

        // Defaults first, then change a transport-level option and ensure
        // the rebuild actually pushes the new value into the freshly
        // constructed ConnectionManager.
        $initial = $this->connectionManagerOf($client);
        self::assertSame(0, $initial->ioBytesWatermark());

        $client->setOption(MemcachedClient::OPT_IO_BYTES_WATERMARK, 16384);

        $rebuilt = $this->connectionManagerOf($client);
        self::assertSame(16384, $rebuilt->ioBytesWatermark());
    }

    private function connectionManagerOf(MemcachedClient $client): ConnectionManager
    {
        $core = (new \ReflectionMethod($client, 'state'))->invoke($client);
        if (!$core instanceof MemcachedClientCore) {
            throw new \LogicException('state() must return MemcachedClientCore');
        }

        return $core->conn;
    }
}
