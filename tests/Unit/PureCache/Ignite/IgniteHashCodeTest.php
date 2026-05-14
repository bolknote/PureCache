<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteHashCode;

final class IgniteHashCodeTest extends TestCase
{
    public function testEmptyStringHashesToZero(): void
    {
        self::assertSame(0, IgniteHashCode::ofString(''));
    }

    public function testSingleByteMatchesItsOrd(): void
    {
        self::assertSame(\ord('a'), IgniteHashCode::ofString('a'));
        self::assertSame(\ord('Z'), IgniteHashCode::ofString('Z'));
    }

    public function testMatchesJavaRecurrenceForAscii(): void
    {
        $value = 'abc';
        $expected = 0;
        foreach (str_split($value) as $char) {
            $expected = ($expected * 31 + \ord($char)) & 0xFFFFFFFF;
        }

        $expected = $expected >= 0x80000000 ? $expected - 0x100000000 : $expected;

        self::assertSame($expected, IgniteHashCode::ofString($value));
    }

    public function testWrapsAroundLikeJavaIntOverflow(): void
    {
        $value = 'PURECACHE_V1';
        $expected = 0;
        foreach (str_split($value) as $char) {
            $expected = ($expected * 31 + \ord($char)) & 0xFFFFFFFF;
        }

        $expected = $expected >= 0x80000000 ? $expected - 0x100000000 : $expected;

        $hash = IgniteHashCode::ofString($value);
        self::assertSame($expected, $hash);
        self::assertGreaterThanOrEqual(-2_147_483_648, $hash);
        self::assertLessThanOrEqual(2_147_483_647, $hash);
    }

    public function testSignExtendsHashesWithHighBitSet(): void
    {
        // 'purecache' has the unsigned 32-bit hash 2556074890, which sets bit 31
        // and must come back as a negative Java int (-1738892406).
        $hash = IgniteHashCode::ofString('purecache');

        self::assertLessThan(0, $hash);
        self::assertSame(-1_738_892_406, $hash);
    }
}
