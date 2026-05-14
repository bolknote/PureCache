<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ServerAvailability;
use PureCache\Memcached\MemcachedClient;

/**
 * The {@link ClientOptionApplier} branches that route libmemcached
 * failover/tuning options into the shared {@code ServerFailureTracker} and
 * {@code ServerSelector} are easy to mis-wire (drop a setter, swap two
 * branches, etc.). These tests assert the end-to-end propagation contract:
 * after a {@code setOption()} call, the corresponding internal state is
 * actually mutated.
 */
final class FailoverOptionPropagationTest extends TestCase
{
    public function testServerFailureLimitConfiguresTracker(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);

        $tracker = $this->trackerOf($client);
        $tracker->setDeadTimeoutSec(60);

        self::assertTrue($client->setOption(MemcachedClient::OPT_SERVER_FAILURE_LIMIT, 2));

        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        $tracker->recordFailure(0);
        self::assertSame(
            ServerAvailability::TemporarilyDisabled,
            $tracker->availability(0),
            'second failure must trip the configured limit',
        );
    }

    public function testServerTimeoutLimitConfiguresTracker(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);

        $tracker = $this->trackerOf($client);
        $tracker->setDeadTimeoutSec(60);

        self::assertTrue($client->setOption(MemcachedClient::OPT_SERVER_TIMEOUT_LIMIT, 1));

        $tracker->recordFailure(0, false);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        $tracker->recordFailure(0, true);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));
    }

    public function testRetryTimeoutConfiguresTracker(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        $client->setOption(MemcachedClient::OPT_SERVER_FAILURE_LIMIT, 5);
        self::assertTrue($client->setOption(MemcachedClient::OPT_RETRY_TIMEOUT, 30));

        $tracker = $this->trackerOf($client);
        $tracker->recordFailure(0);
        self::assertSame(
            ServerAvailability::RetryDelayed,
            $tracker->availability(0),
            'a single failure under a 30s retry timeout must short-circuit routing',
        );
    }

    public function testDeadTimeoutConfiguresTracker(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        self::assertTrue($client->setOption(MemcachedClient::OPT_SERVER_FAILURE_LIMIT, 1));
        self::assertTrue($client->setOption(MemcachedClient::OPT_DEAD_TIMEOUT, 120));

        $tracker = $this->trackerOf($client);
        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));
    }

    public function testRemoveFailedServersConfiguresTracker(): void
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        self::assertTrue($client->setOption(MemcachedClient::OPT_SERVER_FAILURE_LIMIT, 1));
        self::assertTrue($client->setOption(MemcachedClient::OPT_REMOVE_FAILED_SERVERS, true));

        $tracker = $this->trackerOf($client);
        $tracker->recordFailure(0);
        self::assertSame(ServerAvailability::DeadRemoved, $tracker->availability(0));

        self::assertTrue($client->setOption(MemcachedClient::OPT_REMOVE_FAILED_SERVERS, false));
        self::assertSame(
            ServerAvailability::Ok,
            $tracker->availability(0),
            'disabling OPT_REMOVE_FAILED_SERVERS must clear the eviction',
        );
    }

    public function testSortHostsActuallyReordersExistingServers(): void
    {
        $client = new MemcachedClient();
        $client->addServer('zeta.example', 11211);
        $client->addServer('alpha.example', 11211);
        $client->addServer('mu.example', 11211);

        self::assertTrue($client->setOption(MemcachedClient::OPT_SORT_HOSTS, true));

        $hosts = array_column($client->getServerList(), 'host');
        self::assertSame(['alpha.example', 'mu.example', 'zeta.example'], $hosts);
    }

    public function testStoreRetryAndReplicaOptionsAreEchoedBackVerbatim(): void
    {
        $client = new MemcachedClient();
        $client->setOption(MemcachedClient::OPT_STORE_RETRY_COUNT, 7);
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 3);

        self::assertSame(7, $client->getOption(MemcachedClient::OPT_STORE_RETRY_COUNT));
        self::assertSame(3, $client->getOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS));
    }

    /**
     * Returns the shared failure tracker stored on the client's core state.
     * Reaches in via reflection so the test stays decoupled from
     * {@code AbstractCacheClient}'s visibility rules.
     */
    private function trackerOf(MemcachedClient $client): \PureCache\Internal\ServerFailureTracker
    {
        $core = (new \ReflectionMethod($client, 'state'))->invoke($client);
        if (!$core instanceof ClientCoreState) {
            throw new \LogicException('state() must return ClientCoreState');
        }

        return $core->failureTracker;
    }
}
