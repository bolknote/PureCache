<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\CacheClient;
use PureCache\ClientFactory;
use PureCache\Ignite\IgniteClient;
use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

final class ClientFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientFactory::resetRegistry();
        parent::tearDown();
    }

    public function testNullAndEmptyAndMemcachedAliasesYieldMemcachedClient(): void
    {
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create());
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create(''));
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create('memcached'));
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create('Mc'));
    }

    public function testRedisBackend(): void
    {
        self::assertInstanceOf(RedisClient::class, ClientFactory::create('redis'));
    }

    public function testIgniteBackendIsAvailableUnderBothAliases(): void
    {
        self::assertInstanceOf(IgniteClient::class, ClientFactory::create('ignite'));
        self::assertInstanceOf(IgniteClient::class, ClientFactory::create('IG'));
    }

    public function testUnknownBackendThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('valkey');
    }

    public function testRegisterCustomBackendIsCaseInsensitive(): void
    {
        $stub = $this->createMock(CacheClient::class);

        ClientFactory::register('CustomVault', static fn (): CacheClient => $stub);

        self::assertSame($stub, ClientFactory::create('customvault'));
        self::assertSame($stub, ClientFactory::create('  CUSTOMVAULT  '));
    }

    public function testRegisterPassesConstructorArgumentsToFactory(): void
    {
        $captured = [];
        ClientFactory::register('probe', function (?string $pid, ?callable $cb, ?string $conn) use (&$captured): CacheClient {
            $captured = [$pid, $cb, $conn];

            return $this->createMock(CacheClient::class);
        });

        $cb = static function (): void {};
        ClientFactory::create('probe', 'p1', $cb, '127.0.0.1:11211');
        self::assertSame(['p1', $cb, '127.0.0.1:11211'], $captured);
    }

    public function testRegisterOverBuiltinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::register('redis', fn (): CacheClient => $this->createMock(CacheClient::class));
    }

    public function testUnregisterRemovesCustomBackend(): void
    {
        ClientFactory::register('tmp', fn (): CacheClient => $this->createMock(CacheClient::class));
        ClientFactory::unregister('tmp');
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('tmp');
    }

    public function testUnregisterBuiltinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::unregister('redis');
    }

    public function testRegisterOverIgniteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::register('ignite', fn (): CacheClient => $this->createMock(CacheClient::class));
    }
}
