<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;

final class RedisClientSurfaceTest extends TestCase
{
    public function testUnsupportedOptionsReturnNotSupported(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);

        self::assertFalse($client->setOption(RedisClient::OPT_TCP_KEEPALIVE, true));
        self::assertSame(RedisClient::RES_NOT_SUPPORTED, $client->getResultCode());
    }

    public function testMaxKeyLengthIsWiderThanMemcached(): void
    {
        $client = new RedisClient();
        self::assertSame(65_536, $client->maxKeyLength());
    }

    public function testItemSizeLimitInvalidatesPool(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);
        self::assertTrue($client->setOption(RedisClient::OPT_ITEM_SIZE_LIMIT, 1024));
        self::assertSame(1024, $client->getOption(RedisClient::OPT_ITEM_SIZE_LIMIT));
    }

    public function testSetOversizedValueReturnsE2big(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);
        $client->setOption(RedisClient::OPT_ITEM_SIZE_LIMIT, 8);
        self::assertFalse($client->set('big', str_repeat('x', 32)));
        self::assertSame(RedisClient::RES_E2BIG, $client->getResultCode());
    }

    public function testDefaultPortIs6379(): void
    {
        $method = new \ReflectionMethod(RedisClient::class, 'defaultPort');
        self::assertSame(6379, $method->invoke(new RedisClient()));
    }

    public function testUnsupportedOptionMessageMentionsRedis(): void
    {
        $client = new RedisClient();
        self::assertStringContainsString('Redis', $client->unsupportedOptionMessage());
    }

    public function testFlushDelayIsNotSupported(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);
        self::assertFalse($client->flush(1));
        self::assertSame(RedisClient::RES_NOT_SUPPORTED, $client->getResultCode());
    }
}
