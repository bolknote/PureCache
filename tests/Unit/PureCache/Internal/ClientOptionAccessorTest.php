<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Internal\ClientOptionAccessor;
use PureCache\Memcached\MemcachedClient;

final class ClientOptionAccessorTest extends TestCase
{
    public function testSetManyAppliesOptionsAtomically(): void
    {
        $client = new MemcachedClient();
        $accessor = $this->accessor($client);

        self::assertTrue($accessor->setMany([
            MemcachedClient::OPT_PREFIX_KEY => 'app:',
            MemcachedClient::OPT_POLL_TIMEOUT => 500,
        ]));
        self::assertSame('app:', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testGetReturnsStoredOption(): void
    {
        $client = new MemcachedClient();
        $client->setOption(MemcachedClient::OPT_POLL_TIMEOUT, 42);

        $accessor = $this->accessor($client);

        self::assertSame(42, $accessor->get(MemcachedClient::OPT_POLL_TIMEOUT));
    }

    private function accessor(MemcachedClient $client): ClientOptionAccessor
    {
        $method = new \ReflectionMethod($client, 'coordinators');
        $registry = $method->invoke($client);
        if (!$registry instanceof ClientCoordinatorRegistry) {
            throw new \LogicException('coordinators() must return ClientCoordinatorRegistry');
        }

        return $registry->options();
    }
}
