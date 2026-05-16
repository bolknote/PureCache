<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerAvailability;
use PureCache\Internal\ServerFailureTracker;
use PureCache\Internal\ServerSelector;
use PureCache\MemcachedConstants;

final class ServerSelectorTest extends TestCase
{
    public function testPickServerIndexReturnsZeroWhenEmpty(): void
    {
        $selector = new ServerSelector();
        self::assertSame(0, $selector->pickServerIndex('key'));
    }

    public function testModulaDistributionPicksStableIndex(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);
        $selector->setDistribution(MemcachedConstants::DISTRIBUTION_MODULA);

        $first = $selector->pickServerIndex('route-me');
        $second = $selector->pickServerIndex('route-me');
        self::assertSame($first, $second);
        self::assertContains($first, [0, 1]);
    }

    public function testKetamaDistributionAndReplicas(): void
    {
        $selector = new ServerSelector();
        foreach (['h1', 'h2', 'h3'] as $i => $host) {
            $selector->addServer(['host' => $host, 'port' => 11211 + $i, 'weight' => 2]);
        }

        $selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);
        $selector->setLibketamaCompatible(true);

        $primary = $selector->pickServer('k');
        self::assertTrue($primary->isUsable());
        $replicas = $selector->pickReplicaIndices('k', 2);
        self::assertGreaterThanOrEqual(1, \count($replicas));
        self::assertLessThanOrEqual(3, \count($replicas));
        self::assertSame($replicas[0], $primary->index);
    }

    public function testHostMapAndForwardMapRouting(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);
        $selector->setBucket([0, 1], 0, [1, 0]);

        $pick = $selector->pickServer('bucket-key');
        self::assertTrue($pick->isUsable());
        self::assertContains($pick->index, [0, 1]);
    }

    public function testFailureTrackerWalksToLiveServer(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);

        $tracker = new ServerFailureTracker(static fn (): float => 1_000_000.0);
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(300);
        $tracker->recordFailure(0);

        $selector->setFailureTracker($tracker);

        $pick = $selector->pickServer('failover');
        self::assertTrue($pick->isUsable());
        self::assertSame(1, $pick->index);
    }

    public function testKetamaWalkWhenPrimaryIsDown(): void
    {
        $selector = new ServerSelector();
        for ($i = 0; $i < 4; ++$i) {
            $selector->addServer(['host' => 'n'.$i, 'port' => 11211, 'weight' => 1]);
        }

        $selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);

        $tracker = new ServerFailureTracker(static fn (): float => 1_000_000.0);
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(300);

        $primary = $selector->pickServerIndex('salt-walk');
        $tracker->recordFailure($primary);
        $selector->setFailureTracker($tracker);

        $pick = $selector->pickServer('salt-walk');
        self::assertTrue($pick->isUsable());
        self::assertNotSame($primary, $pick->index);
    }

    public function testModulaReplicasAndRandomizedRead(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);
        $selector->addServer(['host' => 'c', 'port' => 3, 'weight' => 1]);

        $indices = $selector->pickReplicaIndices('rep', 2);
        self::assertCount(3, $indices);

        $readIdx = $selector->pickReadIndex('rep', 2, true);
        self::assertContains($readIdx, $indices);
    }

    public function testPickReplicaIndicesEmptyWhenPrimaryUnavailable(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'only', 'port' => 1, 'weight' => 1]);

        $tracker = new ServerFailureTracker(static fn (): float => 1_000_000.0);
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        $tracker->recordFailure(0);

        $selector->setFailureTracker($tracker);

        self::assertSame([], $selector->pickReplicaIndices('x', 1));
        self::assertSame(-1, $selector->pickReadIndex('x', 1, false));
    }

    public function testSortHostsReordersServers(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'z', 'port' => 2, 'weight' => 1]);
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->setSortHosts(true);

        $servers = $selector->getServers();
        self::assertSame('a', $servers[0]['host']);
        self::assertSame('z', $servers[1]['host']);
    }

    public function testHostMapClampsOutOfRangeIndexToZero(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);
        $selector->setBucket([99], 0);

        self::assertSame(0, $selector->pickServerIndex('bucket'));
    }

    public function testKetamaReplicasFillRemainingLiveServersWhenSaltsCollide(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 11212, 'weight' => 1]);
        $selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);

        $indices = $selector->pickReplicaIndices('replica-fill', 3);
        self::assertCount(2, $indices);
    }

    public function testModulaReplicasWalkLiveRingWhenPrimaryIsFiltered(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->addServer(['host' => 'b', 'port' => 2, 'weight' => 1]);
        $selector->addServer(['host' => 'c', 'port' => 3, 'weight' => 1]);

        $tracker = new ServerFailureTracker(static fn (): float => 1_000_000.0);
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(300);

        $primary = $selector->pickServerIndex('replica-ring');
        $tracker->recordFailure($primary);
        $selector->setFailureTracker($tracker);

        $indices = $selector->pickReplicaIndices('replica-ring', 2);
        self::assertGreaterThanOrEqual(2, \count($indices));
        self::assertNotContains($primary, $indices);
    }

    public function testPickReadIndexReturnsNegativeWhenNoReplicas(): void
    {
        $selector = new ServerSelector();
        self::assertSame(-1, $selector->pickReadIndex('none', 1, true));
    }

    public function testResetClearsServersAndBucket(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'a', 'port' => 1, 'weight' => 1]);
        $selector->setBucket([0], 0);
        $selector->reset();

        self::assertSame([], $selector->getServers());
        $pick = $selector->pickServer('k');
        self::assertSame(ServerAvailability::DeadRemoved, $pick->status);
        self::assertFalse($pick->isUsable());
    }
}
