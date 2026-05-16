<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;

final class AddServerConnectionStringTest extends TestCase
{
    public function testAddServerAcceptsRedisUrl(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServer('redis://cache.example.test:6379/2'));

        $servers = $client->getServerList();
        self::assertCount(1, $servers);
        self::assertSame('cache.example.test', $servers[0]['host']);
        self::assertSame(6379, $servers[0]['port']);
    }

    public function testAddServerAcceptsRedissUrlWithTls(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServer('rediss://secure.example.test:6380?cafile=%2Ftmp%2Fca.pem'));

        $servers = $client->getServerList();
        self::assertCount(1, $servers);
        self::assertSame('secure.example.test', $servers[0]['host']);
        self::assertSame(6380, $servers[0]['port']);
    }
}
