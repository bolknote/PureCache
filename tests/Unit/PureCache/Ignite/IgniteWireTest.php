<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteWire;

final class IgniteWireTest extends TestCase
{
    public function testInt8RoundTripIncludingNegatives(): void
    {
        self::assertSame(0, IgniteWire::unpackInt8(IgniteWire::packInt8(0), 0));
        self::assertSame(127, IgniteWire::unpackInt8(IgniteWire::packInt8(127), 0));
        self::assertSame(-1, IgniteWire::unpackInt8(IgniteWire::packInt8(-1), 0));
        self::assertSame(-128, IgniteWire::unpackInt8(IgniteWire::packInt8(-128), 0));
        self::assertSame(255, IgniteWire::unpackUint8(IgniteWire::packInt8(-1), 0));
    }

    public function testInt16IsLittleEndian(): void
    {
        $bytes = IgniteWire::packInt16(0x0102);
        self::assertSame("\x02\x01", $bytes);
        self::assertSame(0x0102, IgniteWire::unpackInt16($bytes, 0));
        self::assertSame(-1, IgniteWire::unpackInt16(IgniteWire::packInt16(-1), 0));
        self::assertSame(32_767, IgniteWire::unpackInt16(IgniteWire::packInt16(32_767), 0));
        self::assertSame(-32_768, IgniteWire::unpackInt16(IgniteWire::packInt16(-32_768), 0));
    }

    public function testInt32IsLittleEndianAndSignedRoundTrip(): void
    {
        $bytes = IgniteWire::packInt32(0x01020304);
        self::assertSame("\x04\x03\x02\x01", $bytes);
        self::assertSame(0x01020304, IgniteWire::unpackInt32($bytes, 0));
        self::assertSame(-1, IgniteWire::unpackInt32(IgniteWire::packInt32(-1), 0));
        self::assertSame(2_147_483_647, IgniteWire::unpackInt32(IgniteWire::packInt32(2_147_483_647), 0));
        self::assertSame(-2_147_483_648, IgniteWire::unpackInt32(IgniteWire::packInt32(-2_147_483_648), 0));
    }

    public function testInt64IsLittleEndianAndSignedRoundTrip(): void
    {
        $bytes = IgniteWire::packInt64(0x0102030405060708);
        self::assertSame("\x08\x07\x06\x05\x04\x03\x02\x01", $bytes);
        self::assertSame(0x0102030405060708, IgniteWire::unpackInt64($bytes, 0));
        self::assertSame(-1, IgniteWire::unpackInt64(IgniteWire::packInt64(-1), 0));
        self::assertSame(\PHP_INT_MAX, IgniteWire::unpackInt64(IgniteWire::packInt64(\PHP_INT_MAX), 0));
        self::assertSame(\PHP_INT_MIN, IgniteWire::unpackInt64(IgniteWire::packInt64(\PHP_INT_MIN), 0));
    }

    public function testReadsAtArbitraryOffset(): void
    {
        $blob = "\x00\x00\x00".IgniteWire::packInt32(42);
        self::assertSame(42, IgniteWire::unpackInt32($blob, 3));
    }

    public function testUnpackThrowsWhenBufferIsTooShort(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to unpack');

        // PHP's unpack() emits a notice/warning on truncated input before
        // returning false; the SUT translates that into an exception.
        // We silence the notice here so PHPUnit doesn't flag the test as risky.
        @IgniteWire::unpackInt32("\x01\x02", 0);
    }
}
