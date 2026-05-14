<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\KetamaContinuum;
use PureCache\Internal\ServerSelector;
use PureCache\Memcached\MemcachedClient;

final class ServerSelectorTest extends TestCase
{
    public function testEmptySelectorReturnsZeroIndex(): void
    {
        $selector = new ServerSelector();

        self::assertSame(0, $selector->pickServerIndex('key'));
    }

    public function testModulaDistributionIgnoresWeightsLikeLibmemcached(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => '127.0.0.2', 'port' => 11211, 'weight' => 3]);

        self::assertSame(0, $selector->pickServerIndex('key-1'));
        self::assertSame(1, $selector->pickServerIndex('key-2'));
        self::assertSame(1, $selector->pickServerIndex('key-3'));
    }

    public function testBucketMapRoutesThroughConfiguredSlots(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $selector->setBucket([1], 0);

        self::assertSame(1, $selector->pickServerIndex('any-key'));

        $selector->setBucket([99], 0);
        self::assertSame(0, $selector->pickServerIndex('invalid-slot'));

        $selector->clearBucket();
        self::assertContains($selector->pickServerIndex('any-key'), [0, 1]);
    }

    public function testEmptyKetamaContinuumReturnsZeroIndex(): void
    {
        $continuum = new KetamaContinuum([]);

        self::assertSame(0, $continuum->pick(123));
    }

    public function testConsistentDistributionReturnsKnownServerIndex(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $selector->setDistribution(MemcachedClient::DISTRIBUTION_CONSISTENT);
        $selector->setHashOption(MemcachedClient::HASH_MD5);

        self::assertContains($selector->pickServerIndex('key'), [0, 1]);
    }

    public function testLibketamaCompatibleDefaultPortVectors(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'cache-a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'cache-b', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'cache-c', 'port' => 11211, 'weight' => 1]);
        $selector->setLibketamaCompatible(true);

        self::assertSame(1, $selector->pickServerIndex('alpha'));
        self::assertSame(0, $selector->pickServerIndex('beta'));
        self::assertSame(0, $selector->pickServerIndex('gamma'));
        self::assertSame(0, $selector->pickServerIndex('delta'));
        self::assertSame(1, $selector->pickServerIndex('epsilon'));
    }

    public function testLibketamaCompatibleNonDefaultPortVectors(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'cache-a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'cache-b', 'port' => 11212, 'weight' => 1]);
        $selector->setLibketamaCompatible(true);

        self::assertSame(1, $selector->pickServerIndex('alpha'));
        self::assertSame(1, $selector->pickServerIndex('beta'));
        self::assertSame(0, $selector->pickServerIndex('gamma'));
        self::assertSame(1, $selector->pickServerIndex('delta'));
        self::assertSame(0, $selector->pickServerIndex('epsilon'));
    }

    public function testWeightedKetamaVectors(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'cache-a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'cache-b', 'port' => 11211, 'weight' => 3]);
        $selector->addServer(['host' => 'cache-c', 'port' => 11211, 'weight' => 2]);
        $selector->setLibketamaCompatible(true);

        self::assertSame(0, $selector->pickServerIndex('alpha'));
        self::assertSame(1, $selector->pickServerIndex('beta'));
        self::assertSame(0, $selector->pickServerIndex('gamma'));
        self::assertSame(0, $selector->pickServerIndex('delta'));
        self::assertSame(1, $selector->pickServerIndex('epsilon'));
    }

    public function testResetClearsServersAndBucket(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $selector->setBucket([0], 0);

        $selector->reset();

        self::assertSame([], $selector->getServers());
        self::assertSame(0, $selector->pickServerIndex('key'));
    }
}
