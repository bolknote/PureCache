<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCacheCallbackInvoker;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Internal\ClientDelayedFetchCoordinator;
use PureCache\Internal\ClientDeleteCoordinator;
use PureCache\Internal\ClientEncodingConfigurator;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ClientKeyedExecutor;
use PureCache\Internal\ClientKeyHelper;
use PureCache\Internal\ClientLifecycleHelper;
use PureCache\Internal\ClientMultiKeyCoordinator;
use PureCache\Internal\ClientOptionAccessor;
use PureCache\Internal\ClientPoolCoordinator;
use PureCache\Internal\ClientReadCoordinator;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Internal\ClientServerRegistry;
use PureCache\Internal\ClientStoreEncoder;
use PureCache\Internal\ClientStoreMultiCoordinator;
use PureCache\Internal\ClientWriteCoordinator;
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

    public function testCoordinatorAccessorsStartUninitializedAndReturnSingletons(): void
    {
        $registry = $this->registry(new MemcachedClient());
        $reflection = new \ReflectionClass(ClientCoordinatorRegistry::class);

        foreach ($this->lazyCoordinatorPropertyNames() as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            self::assertNull(
                $property->getValue($registry),
                \sprintf('Property %s should be null before first access', $propertyName),
            );
        }

        /** @var array<string, array{0: object, 1: class-string}> $instances */
        $instances = [
            'env' => [$registry->env(), ClientCoordinatorEnv::class],
            'health' => [$registry->health(), ClientHealthRecorder::class],
            'routing' => [$registry->routing(), ClientRoutingCoordinator::class],
            'write' => [$registry->write(), ClientWriteCoordinator::class],
            'keyed' => [$registry->keyed(), ClientKeyedExecutor::class],
            'pool' => [$registry->pool(), ClientPoolCoordinator::class],
            'storeEncoder' => [$registry->storeEncoder(), ClientStoreEncoder::class],
            'serverRegistry' => [$registry->serverRegistry(), ClientServerRegistry::class],
            'keyHelper' => [$registry->keyHelper(), ClientKeyHelper::class],
            'delete' => [$registry->delete(), ClientDeleteCoordinator::class],
            'storeMulti' => [$registry->storeMulti(), ClientStoreMultiCoordinator::class],
            'cacheCallback' => [$registry->cacheCallback(), ClientCacheCallbackInvoker::class],
            'read' => [$registry->read(), ClientReadCoordinator::class],
            'delayedFetch' => [$registry->delayedFetch(), ClientDelayedFetchCoordinator::class],
            'multiKey' => [$registry->multiKey(), ClientMultiKeyCoordinator::class],
            'options' => [$registry->options(), ClientOptionAccessor::class],
            'encoding' => [$registry->encoding(), ClientEncodingConfigurator::class],
            'lifecycle' => [$registry->lifecycle(), ClientLifecycleHelper::class],
        ];

        foreach ($instances as $propertyName => [$instance, $expectedClass]) {
            self::assertInstanceOf($expectedClass, $instance, $propertyName);
            $property = $reflection->getProperty($propertyName);
            self::assertSame($instance, $property->getValue($registry), $propertyName);
            self::assertSame($instance, match ($propertyName) {
                'env' => $registry->env(),
                'health' => $registry->health(),
                'routing' => $registry->routing(),
                'write' => $registry->write(),
                'keyed' => $registry->keyed(),
                'pool' => $registry->pool(),
                'storeEncoder' => $registry->storeEncoder(),
                'serverRegistry' => $registry->serverRegistry(),
                'keyHelper' => $registry->keyHelper(),
                'delete' => $registry->delete(),
                'storeMulti' => $registry->storeMulti(),
                'cacheCallback' => $registry->cacheCallback(),
                'read' => $registry->read(),
                'delayedFetch' => $registry->delayedFetch(),
                'multiKey' => $registry->multiKey(),
                'options' => $registry->options(),
                'encoding' => $registry->encoding(),
                'lifecycle' => $registry->lifecycle(),
                default => throw new \LogicException('unexpected coordinator: '.$propertyName),
            }, $propertyName);
        }
    }

    /**
     * @return list<string>
     */
    private function lazyCoordinatorPropertyNames(): array
    {
        return [
            'env',
            'health',
            'routing',
            'write',
            'keyed',
            'pool',
            'storeEncoder',
            'serverRegistry',
            'keyHelper',
            'delete',
            'storeMulti',
            'cacheCallback',
            'read',
            'delayedFetch',
            'multiKey',
            'options',
            'encoding',
            'lifecycle',
        ];
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
