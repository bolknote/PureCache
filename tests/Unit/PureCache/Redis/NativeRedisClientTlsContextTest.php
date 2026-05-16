<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;

final class NativeRedisClientTlsContextTest extends TestCase
{
    public function testStreamContextOptionsIncludeCaFileWhenConfigured(): void
    {
        $caFile = tempnam(sys_get_temp_dir(), 'purecache_ca_');
        self::assertIsString($caFile);
        file_put_contents($caFile, 'dummy');

        $client = new NativeRedisClient(
            host: 'secure.example.test',
            port: 6380,
            useTls: true,
            tlsCaFile: $caFile,
            tlsPeerNameOverride: 'secure.example.test',
        );

        $options = $this->invoke($client, 'streamContextOptions');
        self::assertIsArray($options);
        self::assertArrayHasKey('ssl', $options);
        $ssl = $options['ssl'];
        self::assertIsArray($ssl);
        self::assertSame($caFile, $ssl['cafile'] ?? null);
        self::assertSame('secure.example.test', $ssl['peer_name'] ?? null);

        @unlink($caFile);
    }

    public function testTlsPeerNameMapsLoopbackToLocalhost(): void
    {
        $client = new NativeRedisClient(host: '127.0.0.1', port: 6380, useTls: true);
        self::assertSame('localhost', $this->invoke($client, 'tlsPeerName'));

        $v6 = new NativeRedisClient(host: '::1', port: 6380, useTls: true);
        self::assertSame('localhost', $this->invoke($v6, 'tlsPeerName'));
    }

    private function invoke(NativeRedisClient $client, string $method): mixed
    {
        $reflection = new \ReflectionMethod($client, $method);

        return $reflection->invoke($client);
    }
}
