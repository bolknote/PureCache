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
    #[\Override]
    protected function tearDown(): void
    {
        ClientFactory::resetRegistry();
        parent::tearDown();
    }

    public function testCreateBuildsBuiltinBackends(): void
    {
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create());
        self::assertInstanceOf(MemcachedClient::class, ClientFactory::create('mc'));
        self::assertInstanceOf(RedisClient::class, ClientFactory::create('redis'));
        self::assertInstanceOf(IgniteClient::class, ClientFactory::create('ignite'));
        self::assertInstanceOf(IgniteClient::class, ClientFactory::create('ig'));
    }

    public function testCreateRejectsUnknownBackend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('not-a-backend');
    }

    public function testRegisterRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::register('   ', static fn (): CacheClient => new MemcachedClient());
    }

    public function testUnregisterRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::unregister("\t");
    }

    public function testCreateUsesRegisteredBackend(): void
    {
        ClientFactory::register('custom', static fn (): CacheClient => new MemcachedClient());

        $client = ClientFactory::create('custom');
        self::assertInstanceOf(MemcachedClient::class, $client);
    }

    public function testUnregisterRemovesRegisteredBackend(): void
    {
        ClientFactory::register('temp', static fn (): CacheClient => new MemcachedClient());
        ClientFactory::unregister('temp');

        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create('temp');
    }

    public function testRegisterRejectsBuiltinBackendName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::register('redis', static fn (): CacheClient => new MemcachedClient());
    }

    public function testUnregisterRejectsBuiltinBackendName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::unregister('mc');
    }
}
