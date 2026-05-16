<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ServerAvailability;
use PureCache\Memcached\Internal\MemcachedClientCore;

final class ClientHealthRecorderTest extends TestCase
{
    public function testRecordServerFailureIgnoresNullServerIndex(): void
    {
        $core = MemcachedClientCore::createFresh();
        $recorder = new ClientHealthRecorder($this->env($core));

        $recorder->recordServerFailure(null, new \RuntimeException('pool'));

        self::assertSame(0, $core->lastErrorErrno);
        self::assertNull($core->lastDisconnectedServer);
    }

    public function testRecordServerFailureIgnoresUnknownServerIndex(): void
    {
        $core = MemcachedClientCore::createFresh();
        $recorder = new ClientHealthRecorder($this->env($core));

        $recorder->recordServerFailure(99, new \RuntimeException('missing', 7));

        self::assertSame(7, $core->lastErrorErrno);
        self::assertNull($core->lastDisconnectedServer);
    }

    public function testRecordServerSuccessIgnoresNullAndNegativeIndex(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 1]);
        $core->failureTracker->setFailureLimit(1);
        $core->failureTracker->setDeadTimeoutSec(60);
        $core->failureTracker->recordFailure(0, false);

        $recorder = new ClientHealthRecorder($this->env($core));

        $recorder->recordServerSuccess(null);
        $recorder->recordServerSuccess(-1);

        self::assertNull($core->lastDisconnectedServer);
        self::assertSame(ServerAvailability::TemporarilyDisabled, $core->failureTracker->availability(0));
    }

    private function env(MemcachedClientCore $core): ClientCoordinatorEnv
    {
        return new ClientCoordinatorEnv(
            $core,
            static function (int $code, ?string $message = null) use ($core): void {
                $core->resultCode = $code;
                $core->resultMessage = $message ?? '';
            },
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => $core->optionInt($option, $default),
            static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
            static fn (string $key): string => $key,
            static fn (string $key): string => $key,
            static fn (string $_key): bool => true,
        );
    }
}
