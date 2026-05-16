<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

final class RedisTlsOptionsTest extends TestCase
{
    public function testTlsOptionsAreRejectedOnMemcachedBackend(): void
    {
        $client = new MemcachedClient();
        self::assertFalse($client->setOption(MemcachedClient::OPT_TLS_CA_FILE, '/tmp/ca.pem'));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
    }

    public function testTlsPeerNameOptionRebuildsRedisPool(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServers([
            ['host' => 'cache.example.test', 'port' => 6380, 'weight' => 0, 'tls' => true],
        ]));

        self::assertTrue($client->setOption(RedisClient::OPT_TLS_PEER_NAME, 'cache.example.test'));
        self::assertSame('cache.example.test', $client->getOption(RedisClient::OPT_TLS_PEER_NAME));
    }

    public function testTlsCaFilePatchesTlsServers(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServers([
            ['host' => 'secure.example.test', 'port' => 6380, 'weight' => 0, 'tls' => true],
        ]));

        self::assertTrue($client->setOption(RedisClient::OPT_TLS_CA_FILE, '/tmp/ca.pem'));
        self::assertSame('/tmp/ca.pem', $client->getOption(RedisClient::OPT_TLS_CA_FILE));
    }

    public function testUnsupportedTcpKeepaliveOptionReturnsNotSupported(): void
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 6379);

        self::assertFalse($client->setOption(RedisClient::OPT_TCP_KEEPALIVE, true));
        self::assertSame(RedisClient::RES_NOT_SUPPORTED, $client->getResultCode());
        self::assertStringContainsString('not supported', $client->getResultMessage());
    }
}
