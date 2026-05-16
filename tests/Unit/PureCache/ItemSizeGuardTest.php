<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ItemSizeGuard;
use PureCache\MemcachedConstants;

final class ItemSizeGuardTest extends TestCase
{
    public function testEffectiveReadLimitUsesOptionWhenPositive(): void
    {
        self::assertSame(1024, ItemSizeGuard::effectiveReadLimit(1024));
    }

    public function testEffectiveReadLimitFallsBackToAbsoluteWhenOptionZero(): void
    {
        self::assertSame(ItemSizeGuard::ABSOLUTE_MAX_BYTES, ItemSizeGuard::effectiveReadLimit(0));
    }

    public function testEffectiveReadLimitNeverExceedsAbsoluteCap(): void
    {
        self::assertSame(
            ItemSizeGuard::ABSOLUTE_MAX_BYTES,
            ItemSizeGuard::effectiveReadLimit(ItemSizeGuard::ABSOLUTE_MAX_BYTES + 1),
        );
    }

    public function testExceedsReadLimit(): void
    {
        self::assertFalse(ItemSizeGuard::exceedsReadLimit(100, 200));
        self::assertTrue(ItemSizeGuard::exceedsReadLimit(201, 200));
    }

    public function testReadLimitResultCode(): void
    {
        self::assertNull(ItemSizeGuard::readLimitResultCode(10, 100));
        self::assertSame(MemcachedConstants::RES_E2BIG, ItemSizeGuard::readLimitResultCode(101, 100));
    }

    public function testAssertWithinLimitAliasesReadLimitResultCode(): void
    {
        self::assertSame(
            ItemSizeGuard::readLimitResultCode(101, 100),
            ItemSizeGuard::assertWithinLimit(101, 100),
        );
    }

    public function testRejectOversizedVa(): void
    {
        self::assertFalse(ItemSizeGuard::rejectOversizedVa(100, 200));
        self::assertTrue(ItemSizeGuard::rejectOversizedVa(201, 200));
    }

    public function testRejectOversizedDeclaredBody(): void
    {
        self::assertFalse(ItemSizeGuard::rejectOversizedDeclaredBody(64, 128));
        self::assertTrue(ItemSizeGuard::rejectOversizedDeclaredBody(129, 128));
    }
}
