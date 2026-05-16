<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Memcached\MemcachedClient;

final class ClientCoordinatorRegistryTest extends TestCase
{
    public function testLazyCoordinatorsAreSingletonsWithinRegistry(): void
    {
        $registry = $this->registry(new MemcachedClient());

        self::assertSame($registry->env(), $registry->env());
        self::assertSame($registry->routing(), $registry->routing());
        self::assertSame($registry->read(), $registry->read());
    }

    public function testRoutingSetsNoServersWhenPoolEmpty(): void
    {
        $client = new MemcachedClient();
        $this->registry($client)->routing()->ensureServersAvailable();

        self::assertSame(MemcachedClient::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testServerRegistryWiresThroughRegistry(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);

        self::assertNotSame([], $client->getServerList());
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testAllCoordinatorAccessorsLazyInitialize(): void
    {
        $registry = $this->registry(new MemcachedClient());

        $registry->write();
        $registry->keyed();
        $registry->pool();
        $registry->storeEncoder();
        $registry->serverRegistry();
        $registry->keyHelper();
        $registry->delete();
        $registry->storeMulti();
        $registry->cacheCallback();
        $registry->delayedFetch();
        $registry->multiKey();
        $registry->options();
        $registry->encoding();
        $registry->lifecycle();

        self::assertSame($registry->write(), $registry->write());
    }

    private function registry(MemcachedClient $client): ClientCoordinatorRegistry
    {
        $method = new \ReflectionMethod($client, 'coordinators');
        $registry = $method->invoke($client);
        if (!$registry instanceof ClientCoordinatorRegistry) {
            throw new \LogicException('coordinators() must return ClientCoordinatorRegistry');
        }

        return $registry;
    }
}
