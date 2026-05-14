<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\Expiration;

final class ExpirationTest extends TestCase
{
    public function testZeroOrNegativeMeansNoExpiry(): void
    {
        self::assertNull(Expiration::toRelativeSeconds(0));
        self::assertNull(Expiration::toRelativeSeconds(-1));
        self::assertNull(Expiration::toRelativeSeconds(\PHP_INT_MIN));
    }

    public function testSmallValuesArePassedThroughAsRelativeSeconds(): void
    {
        self::assertSame(1, Expiration::toRelativeSeconds(1));
        self::assertSame(60, Expiration::toRelativeSeconds(60));
        self::assertSame(
            Expiration::MEMCACHED_RELATIVE_LIMIT_SECONDS,
            Expiration::toRelativeSeconds(Expiration::MEMCACHED_RELATIVE_LIMIT_SECONDS),
        );
    }

    public function testAboveCutoffIsTreatedAsAbsoluteUnixTimestamp(): void
    {
        $now = 1_700_000_000;
        $future = $now + 90;

        self::assertSame(90, Expiration::toRelativeSeconds($future, $now));
    }

    public function testPastAbsoluteTimestampClampsToOneSecond(): void
    {
        $now = 1_700_000_000;
        self::assertSame(1, Expiration::toRelativeSeconds($now - 5, $now));
    }

    public function testCutoffPlusOneIsAlreadyAbsolute(): void
    {
        $cutoff = Expiration::MEMCACHED_RELATIVE_LIMIT_SECONDS;
        $now = $cutoff + 1_000;

        self::assertSame(1, Expiration::toRelativeSeconds($cutoff + 1, $now));
    }
}
