<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteTransportException;
use PureCache\Ignite\Internal\IgniteTransportFailure;

final class IgniteTransportExceptionTest extends TestCase
{
    public function testExposesTransportFailureReason(): void
    {
        $exception = new IgniteTransportException(IgniteTransportFailure::ReadTruncated);

        self::assertSame(IgniteTransportFailure::ReadTruncated, $exception->reason);
        self::assertSame('Ignite read truncated', $exception->getMessage());
    }

    public function testWriteTimedOutIsDistinctFromReadTimedOut(): void
    {
        $write = new IgniteTransportException(IgniteTransportFailure::WriteTimedOut);
        $read = new IgniteTransportException(IgniteTransportFailure::ReadTimedOut);

        self::assertSame('Ignite write timed out', $write->getMessage());
        self::assertSame('Ignite read timed out', $read->getMessage());
        self::assertNotSame($write->reason, $read->reason);
    }
}
