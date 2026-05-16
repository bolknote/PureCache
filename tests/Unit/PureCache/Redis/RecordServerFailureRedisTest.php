<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ServerAvailability;
use PureCache\Redis\RedisClient;

final class RecordServerFailureRedisTest extends TestCase
{
    public function testRecordServerFailureMarksShardTemporarilyDisabled(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $this->callRecordFailure($client, 0, new \RuntimeException('connection reset'));
        self::assertSame(ServerAvailability::TemporarilyDisabled, $tracker->availability(0));
    }

    public function testRecordServerSuccessClearsFailure(): void
    {
        [$client, $tracker] = $this->newClientWithTracker();
        $tracker->setFailureLimit(1);
        $tracker->setDeadTimeoutSec(60);

        $this->callRecordFailure($client, 0, new \RuntimeException('boom'));
        $this->callRecordSuccess($client, 0);
        self::assertSame(ServerAvailability::Ok, $tracker->availability(0));
    }

    /**
     * @return array{0: RedisClient, 1: \PureCache\Internal\ServerFailureTracker}
     */
    private function newClientWithTracker(): array
    {
        $client = new RedisClient();
        $client->addServer('10.0.0.0', 6379);

        $method = new \ReflectionMethod($client, 'state');
        $core = $method->invoke($client);
        if (!$core instanceof ClientCoreState) {
            throw new \LogicException('state() must return ClientCoreState');
        }

        return [$client, $core->failureTracker];
    }

    private function callRecordFailure(RedisClient $client, ?int $idx, \Throwable $throwable): void
    {
        (new \ReflectionMethod($client, 'recordServerFailure'))->invoke($client, $idx, $throwable);
    }

    private function callRecordSuccess(RedisClient $client, ?int $idx): void
    {
        (new \ReflectionMethod($client, 'recordServerSuccess'))->invoke($client, $idx);
    }
}
