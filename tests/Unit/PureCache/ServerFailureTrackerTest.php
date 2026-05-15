<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerAvailability;
use PureCache\Internal\ServerFailureTracker;

/**
 * Time-sensitive transitions of the libmemcached-shaped failure state machine.
 *
 * Every test injects a deterministic clock closure so the retry/dead windows
 * advance without touching {@code microtime()}, keeping the suite stable
 * across slow CI machines.
 */
final class ServerFailureTrackerTest extends TestCase
{
    public function testFreshTrackerReportsOk(): void
    {
        $tracker = new ServerFailureTracker();
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        self::assertTrue($tracker->isUsable(0));
    }

    public function testFailureLimitDisarmsServerForDeadWindow(): void
    {
        $now = 1000.0;
        $tracker = new ServerFailureTracker(static function () use (&$now): float { return $now; });
        $tracker->setFailureLimit(3);
        $tracker->setDeadTimeoutSec(60);

        $tracker->recordFailure(0);
        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));

        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));

        $now = 1030.0;
        self::assertGreaterThan(1000.0, $now);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));

        $now = 1060.1;
        self::assertGreaterThan(1030.0, $now);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    public function testRetryTimeoutShortCircuitsRoutingButLeavesServerLive(): void
    {
        $now = 100.0;
        $tracker = new ServerFailureTracker(static function () use (&$now): float { return $now; });
        $tracker->setFailureLimit(5);
        $tracker->setRetryTimeoutSec(15);

        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::RetryDelayed, $tracker->availability(0));
        self::assertTrue($tracker->isUsable(0));

        $now = 116.0;
        self::assertGreaterThan(100.0, $now);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    public function testTimeoutLimitIsTrackedSeparatelyFromGenericFailures(): void
    {
        $now = 0.0;
        $tracker = new ServerFailureTracker(static function () use (&$now): float { return $now; });
        $tracker->setTimeoutLimit(2);
        $tracker->setDeadTimeoutSec(5);

        $tracker->recordFailure(0, false);
        $tracker->recordFailure(0, false);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));

        $tracker->recordFailure(0, true);
        $tracker->recordFailure(0, true);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));
    }

    public function testRecordSuccessClearsAllCounters(): void
    {
        $tracker = new ServerFailureTracker();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));

        $tracker->recordSuccess(0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    public function testRemoveFailedServersEvictsTheServerPermanently(): void
    {
        $now = 0.0;
        $tracker = new ServerFailureTracker(static function () use (&$now): float { return $now; });
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);

        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::DeadRemoved, $tracker->availability(0));

        $now = 86400.0;
        self::assertGreaterThan(0.0, $now);
        self::assertSame(ServerAvailability::DeadRemoved, $tracker->availability(0), 'evicted server must stay evicted across the dead window');
        self::assertFalse($tracker->isUsable(0));
    }

    public function testTogglingRemoveFailedOffWipesPreviouslyEvictedFlag(): void
    {
        $tracker = new ServerFailureTracker();
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::DeadRemoved, $tracker->availability(0));

        $tracker->setRemoveFailed(false);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    public function testAvailableIndicesReturnsListOfLiveServersOnly(): void
    {
        $now = 0.0;
        $tracker = new ServerFailureTracker(static function () use (&$now): float { return $now; });
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $tracker->recordFailure(1);
        self::assertSame([0, 2, 3], $tracker->availableIndices(4));
    }

    public function testForgetClearsSingleServerState(): void
    {
        $tracker = new ServerFailureTracker();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);
        $tracker->recordFailure(0);
        $tracker->recordFailure(1);

        $tracker->forget(0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(1));
    }

    public function testForgetAllResetsEverything(): void
    {
        $tracker = new ServerFailureTracker();
        $tracker->setFailureLimit(1);
        $tracker->setRemoveFailed(true);
        $tracker->recordFailure(0);
        $tracker->recordFailure(1);

        $tracker->forgetAll();
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        self::assertSame(ServerAvailability::Ok, $tracker->availability(1));
    }
}
