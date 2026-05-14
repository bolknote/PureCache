<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerAvailability;
use PureCache\Internal\ServerFailureTracker;
use PureCache\Memcached\Internal\TimeoutException;
use PureCache\Memcached\MemcachedClient;

/**
 * Coverage for {@code AbstractCacheClient::recordServerFailure()} / {@code recordServerSuccess()}.
 *
 * These hooks are the bridge between protocol-level exceptions thrown by
 * each backend's transport and the shared {@code ServerFailureTracker}. The
 * exception-type / message detection that splits generic failures from
 * timeouts is straightforward to break (e.g. by tightening the
 * {@code stripos} into {@code strpos}), so the contract is pinned with
 * direct tests against the public-but-internal {@code recordServerFailure()}.
 */
final class RecordServerFailureTest extends TestCase
{
    public function testTimeoutExceptionsIncrementTheTimeoutCounter(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setTimeoutLimit(2);
        $tracker->setDeadTimeoutSec(60);

        $this->callRecordFailure($client, 0, new TimeoutException('read timeout'));
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
        $this->callRecordFailure($client, 0, new TimeoutException('read timeout'));
        self::assertSame(
            ServerAvailability::TemporarilyDisabled,
            $tracker->availability(0),
            'two timeouts must satisfy OPT_SERVER_TIMEOUT_LIMIT=2',
        );
    }

    public function testTimeoutSubstringInGenericMessageIsClassifiedAsTimeout(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setTimeoutLimit(1);
        $tracker->setDeadTimeoutSec(60);

        // Generic RuntimeException with a timeout-flavoured message should
        // still bump the timeout counter (PECL parity: libmemcached looks at
        // the wire-level error type, we look at the message body).
        $this->callRecordFailure($client, 0, new \RuntimeException('connection timed out'));
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));
    }

    public function testNonTimeoutFailuresOnlyBumpTheGenericLimit(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setTimeoutLimit(1);

        $this->callRecordFailure($client, 0, new \RuntimeException('connection reset'));
        self::assertSame(
            ServerAvailability::Ok,
            $tracker->availability(0),
            'generic failure must not move the timeout counter',
        );
    }

    public function testRecordServerSuccessClearsAllCounters(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $this->callRecordFailure($client, 0, new \RuntimeException('boom'));
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));

        $this->callRecordSuccess($client, 0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    public function testRecordServerFailureWithNullIndexIsSafe(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setFailureLimit(1);

        $this->callRecordFailure($client, null, new \RuntimeException('no shard'));
        // Nothing to assert beyond "no exception thrown" — tracker should
        // remain untouched.
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    /**
     * @return array{0: MemcachedClient, 1: ServerFailureTracker}
     */
    private function newClientWithTracker(): array
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);

        $core = (new \ReflectionMethod($client, 'state'))->invoke($client);

        return [$client, $core->failureTracker];
    }

    private function callRecordFailure(MemcachedClient $client, ?int $idx, \Throwable $throwable): void
    {
        (new \ReflectionMethod($client, 'recordServerFailure'))->invoke($client, $idx, $throwable);
    }

    private function callRecordSuccess(MemcachedClient $client, ?int $idx): void
    {
        (new \ReflectionMethod($client, 'recordServerSuccess'))->invoke($client, $idx);
    }
}
