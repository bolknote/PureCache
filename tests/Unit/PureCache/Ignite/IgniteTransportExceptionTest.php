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
}
