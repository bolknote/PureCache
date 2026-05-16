<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteTransportException;
use PureCache\Ignite\Internal\IgniteTransportFailure;

final class IgniteTransportExceptionTest extends TestCase
{
    public function testConnectFailedFactoryIncludesErrnoAndMessage(): void
    {
        $ex = IgniteTransportException::connectFailed('connection refused', 111);

        self::assertSame(IgniteTransportFailure::ConnectFailed, $ex->reason);
        self::assertStringContainsString('connection refused', $ex->getMessage());
        self::assertStringContainsString('111', $ex->getMessage());
    }

    public function testFrameLengthInvalidUsesDefaultMessage(): void
    {
        $ex = IgniteTransportException::frameLengthInvalid();

        self::assertSame(IgniteTransportFailure::FrameLengthInvalid, $ex->reason);
        self::assertSame(IgniteTransportFailure::FrameLengthInvalid->defaultMessage(), $ex->getMessage());
    }

    public function testFrameLengthExceededIncludesBounds(): void
    {
        $ex = IgniteTransportException::frameLengthExceeded(9_999_999, 8_388_608);

        self::assertSame(IgniteTransportFailure::FrameLengthExceeded, $ex->reason);
        self::assertStringContainsString('9999999', $ex->getMessage());
        self::assertStringContainsString('8388608', $ex->getMessage());
    }

    public function testHandshakeFailedPreservesDetail(): void
    {
        $ex = IgniteTransportException::handshakeFailed('unexpected opcode');

        self::assertSame(IgniteTransportFailure::HandshakeFailed, $ex->reason);
        self::assertSame('unexpected opcode', $ex->getMessage());
    }

    public function testConstructorFallsBackToReasonDefaultWhenMessageEmpty(): void
    {
        $ex = new IgniteTransportException(IgniteTransportFailure::ReadTimedOut);

        self::assertSame(IgniteTransportFailure::ReadTimedOut->defaultMessage(), $ex->getMessage());
    }

    /**
     * @return array<string, array{IgniteTransportFailure}>
     */
    public static function failureDefaultMessages(): array
    {
        $cases = [];
        foreach (IgniteTransportFailure::cases() as $case) {
            $cases[$case->name] = [$case];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureDefaultMessages')]
    public function testFailureDefaultMessageIsNonEmpty(IgniteTransportFailure $failure): void
    {
        self::assertNotSame('', $failure->defaultMessage());
    }
}
