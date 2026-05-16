<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteHashCode;

final class IgniteHashCodeTest extends TestCase
{
    public function testOfStringMatchesJavaStringHashCodeForAscii(): void
    {
        self::assertSame(0, IgniteHashCode::ofString(''));
        self::assertSame(97, IgniteHashCode::ofString('a'));
        self::assertSame(235522890, IgniteHashCode::ofString('PureCache'));
    }

    public function testOfStringSignExtendsHighBitWhenUnsignedHashIsHigh(): void
    {
        self::assertSame(-744952576, IgniteHashCode::ofString(str_repeat('x', 6)));
    }
}
