<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerAvailability;
use PureCache\Internal\ServerFailureTracker;
use PureCache\Internal\ServerSelector;
use PureCache\MemcachedConstants;

/**
 * Routing-level unit tests for {@see ServerSelector}: host sort, failover
 * routing through the failure tracker, and replica selection. Network
 * concerns (TCP options, timeouts) belong to the integration suite —
 * everything here is in-memory and deterministic.
 */
final class ServerSelectorTest extends TestCase
{
    public function testSortHostsReordersExistingServersOnEnableAndKeepsThemSortedOnInsert(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => 'c.example', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'a.example', 'port' => 11211, 'weight' => 1]);

        self::assertSame(['c.example', 'a.example'], array_column($selector->getServers(), 'host'));

        $selector->setSortHosts(true);
        self::assertSame(['a.example', 'c.example'], array_column($selector->getServers(), 'host'));

        $selector->addServer(['host' => 'b.example', 'port' => 11211, 'weight' => 1]);
        self::assertSame(['a.example', 'b.example', 'c.example'], array_column($selector->getServers(), 'host'));
    }

    public function testSortHostsBreaksTiesByPort(): void
    {
        $selector = new ServerSelector();
        $selector->setSortHosts(true);
        $selector->addServer(['host' => 'a.example', 'port' => 11213, 'weight' => 1]);
        $selector->addServer(['host' => 'a.example', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'a.example', 'port' => 11212, 'weight' => 1]);

        self::assertSame([11211, 11212, 11213], array_column($selector->getServers(), 'port'));
    }

    public function testDisablingSortHostsLeavesExistingOrderIntact(): void
    {
        $selector = new ServerSelector();
        $selector->setSortHosts(true);
        $selector->addServer(['host' => 'b.example', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => 'a.example', 'port' => 11211, 'weight' => 1]);
        self::assertSame(['a.example', 'b.example'], array_column($selector->getServers(), 'host'));

        $selector->setSortHosts(false);
        $selector->addServer(['host' => 'z.example', 'port' => 11211, 'weight' => 1]);
        self::assertSame(['a.example', 'b.example', 'z.example'], array_column($selector->getServers(), 'host'));
    }

    public function testPickServerRoutesAroundTemporarilyDisabledServers(): void
    {
        [$selector, $tracker] = $this->newSelectorWithThree();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $key = 'item-7';
        $initial = $selector->pickServer($key)->index;
        $tracker->recordFailure($initial);

        $rerouted = $selector->pickServer($key);
        self::assertNotSame($initial, $rerouted->index);
        self::assertTrue($rerouted->isUsable());
        self::assertSame(ServerAvailability::Ok, $rerouted->status);
    }

    public function testPickServerReportsRetryDelayedWhenOnlyOneServerExists(): void
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        $selector->addServer(['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1]);
        $tracker->setFailureLimit(2);
        $tracker->setRetryTimeoutSec(30);
        $tracker->recordFailure(0);

        $pick = $selector->pickServer('foo');
        self::assertSame(0, $pick->index);
        self::assertSame(ServerAvailability::RetryDelayed, $pick->status);
    }

    public function testPickServerReturnsDeadRemovedSentinelWhenAllServersAreEvicted(): void
    {
        [$selector, $tracker] = $this->newSelectorWithThree();
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        for ($i = 0; $i < 3; ++$i) {
            $tracker->recordFailure($i);
        }

        $pick = $selector->pickServer('any-key');
        self::assertFalse($pick->isUsable());
    }

    public function testRecordSuccessClearsBackoffWindow(): void
    {
        [$selector, $tracker] = $this->newSelectorWithThree();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(120);

        $idx = $selector->pickServer('foo')->index;
        $tracker->recordFailure($idx);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability($idx));

        $tracker->recordSuccess($idx);
        self::assertSame(ServerAvailability::Ok, $tracker->availability($idx));
    }

    public function testReplicaIndicesCoverPrimaryPlusReplicasWithoutDuplicates(): void
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        for ($i = 0; $i < 5; ++$i) {
            $selector->addServer(['host' => '10.0.0.'.$i, 'port' => 11211, 'weight' => 1]);
        }

        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $key) {
            $indices = $selector->pickReplicaIndices($key, 2);
            self::assertCount(3, $indices, 'expected primary + 2 replicas');
            self::assertCount(3, array_unique($indices), 'replicas must be unique');
            foreach ($indices as $idx) {
                self::assertGreaterThanOrEqual(0, $idx);
                self::assertLessThan(5, $idx);
            }
        }
    }

    public function testReplicaIndicesShrinkWhenLiveServerCountIsLow(): void
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        $selector->addServer(['host' => '10.0.0.0', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => '10.0.0.1', 'port' => 11211, 'weight' => 1]);

        $indices = $selector->pickReplicaIndices('key', 5);
        self::assertSame([0, 1], $this->sortedUnique($indices));
    }

    public function testRandomizeReplicaReadVariesAcrossInvocations(): void
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        for ($i = 0; $i < 4; ++$i) {
            $selector->addServer(['host' => '10.0.0.'.$i, 'port' => 11211, 'weight' => 1]);
        }

        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $seen[$selector->pickReadIndex('item', 2, true)] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'expected at least two distinct read replicas');
    }

    public function testRandomizeReplicaReadFallsBackToPrimaryWhenReplicaCountIsZero(): void
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        for ($i = 0; $i < 3; ++$i) {
            $selector->addServer(['host' => '10.0.0.'.$i, 'port' => 11211, 'weight' => 1]);
        }

        $primary = $selector->pickServer('only-primary')->index;
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($primary, $selector->pickReadIndex('only-primary', 0, true));
        }
    }

    public function testReadFromConsistentReplicaSetIsStableWhenRandomizationIsOff(): void
    {
        $selector = $this->newConsistentSelector(['10.0.0.0', '10.0.0.1', '10.0.0.2', '10.0.0.3']);
        $key = 'cas-key';
        $first = $selector->pickReadIndex($key, 2, false);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($first, $selector->pickReadIndex($key, 2, false));
        }
    }

    public function testReplicaIndicesSkipDeadServersInTheTracker(): void
    {
        [$selector, $tracker] = $this->newSelectorWithThree();
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        $tracker->recordFailure(1);

        for ($i = 0; $i < 10; ++$i) {
            $indices = $selector->pickReplicaIndices('item-'.$i, 5);
            self::assertNotContains(1, $indices, 'dead server must never appear in the replica set');
            self::assertLessThanOrEqual(2, \count($indices), 'only 2 live servers remain');
        }
    }

    public function testReplicaReadFallsBackToPrimaryWhenAllReplicasAreUnusable(): void
    {
        [$selector, $tracker] = $this->newSelectorWithThree();
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        $tracker->recordFailure(0);
        $tracker->recordFailure(2);

        $primary = $selector->pickServer('only-survivor')->index;
        self::assertSame(1, $primary);
        self::assertSame(1, $selector->pickReadIndex('only-survivor', 2, true));
    }

    public function testSortHostsInvalidatesConsistentRing(): void
    {
        $selector = $this->newConsistentSelector(['z.example', 'a.example', 'm.example']);
        $beforeSort = [];
        for ($i = 0; $i < 6; ++$i) {
            $beforeSort['k'.$i] = $selector->pickServer('k'.$i)->index;
        }

        $selector->setSortHosts(true);

        $hosts = array_column($selector->getServers(), 'host');
        self::assertSame(['a.example', 'm.example', 'z.example'], $hosts);

        $afterSort = [];
        for ($i = 0; $i < 6; ++$i) {
            $afterSort['k'.$i] = $selector->pickServer('k'.$i)->index;
        }

        self::assertNotSame(
            $beforeSort,
            $afterSort,
            'sorting the server list should re-shuffle the ketama ring routing',
        );
    }

    /**
     * @return array{0: ServerSelector, 1: ServerFailureTracker}
     */
    private function newSelectorWithThree(): array
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        $selector->addServer(['host' => '10.0.0.0', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => '10.0.0.1', 'port' => 11211, 'weight' => 1]);
        $selector->addServer(['host' => '10.0.0.2', 'port' => 11211, 'weight' => 1]);

        return [$selector, $tracker];
    }

    /**
     * @param list<string> $hosts
     */
    private function newConsistentSelector(array $hosts): ServerSelector
    {
        $selector = new ServerSelector();
        $tracker = new ServerFailureTracker();
        $selector->setFailureTracker($tracker);
        $selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);
        $selector->setHashOption(MemcachedConstants::HASH_MD5);
        foreach ($hosts as $host) {
            $selector->addServer(['host' => $host, 'port' => 11211, 'weight' => 1]);
        }

        return $selector;
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }
}
