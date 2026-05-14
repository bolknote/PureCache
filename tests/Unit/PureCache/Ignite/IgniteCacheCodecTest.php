<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteWire;

final class IgniteCacheCodecTest extends TestCase
{
    public function testWrapperRoundTripsCasAndPayload(): void
    {
        $bytes = IgniteCacheCodec::encodeWrapper(42, 0x10A0, 'hello');

        self::assertSame(IgniteCacheCodec::WRAPPER_HEADER_SIZE + 5, \strlen($bytes));

        $decoded = IgniteCacheCodec::decodeWrapper($bytes);
        self::assertNotNull($decoded);
        self::assertSame(42, $decoded[0]);
        self::assertSame(0x10A0, $decoded[1]);
        self::assertSame('hello', $decoded[2]);
    }

    public function testWrapperRoundTripsExtremeCas(): void
    {
        $decodedMax = IgniteCacheCodec::decodeWrapper(IgniteCacheCodec::encodeWrapper(\PHP_INT_MAX, 0, ''));
        self::assertNotNull($decodedMax);
        self::assertSame(\PHP_INT_MAX, $decodedMax[0]);
        self::assertSame('', $decodedMax[2]);

        $decodedMin = IgniteCacheCodec::decodeWrapper(IgniteCacheCodec::encodeWrapper(\PHP_INT_MIN, 0, ''));
        self::assertNotNull($decodedMin);
        self::assertSame(\PHP_INT_MIN, $decodedMin[0]);
    }

    public function testWrapperRejectsTooShortInputs(): void
    {
        self::assertNull(IgniteCacheCodec::decodeWrapper(''));
        self::assertNull(IgniteCacheCodec::decodeWrapper(str_repeat("\0", IgniteCacheCodec::WRAPPER_HEADER_SIZE - 1)));
    }

    public function testWrapperRejectsImpossiblePayloadLength(): void
    {
        $header = IgniteWire::packInt64(1)
            .IgniteWire::packInt32(0)
            .IgniteWire::packInt32(1024);

        self::assertNull(IgniteCacheCodec::decodeWrapper($header));
    }

    public function testEncodeStringObjectMatchesProtocolLayout(): void
    {
        $bytes = IgniteCacheCodec::encodeStringObject('ABC');
        self::assertSame(IgniteProtocol::TYPE_STRING, IgniteWire::unpackUint8($bytes, 0));
        self::assertSame(3, IgniteWire::unpackInt32($bytes, 1));
        self::assertSame('ABC', substr($bytes, 5));
    }

    public function testEncodeByteArrayObjectMatchesProtocolLayout(): void
    {
        $bytes = IgniteCacheCodec::encodeByteArrayObject("\xDE\xAD\xBE\xEF");
        self::assertSame(IgniteProtocol::TYPE_BYTE_ARRAY, IgniteWire::unpackUint8($bytes, 0));
        self::assertSame(4, IgniteWire::unpackInt32($bytes, 1));
        self::assertSame("\xDE\xAD\xBE\xEF", substr($bytes, 5));
    }

    public function testEncodeNullObjectIsOneByteSentinel(): void
    {
        self::assertSame(IgniteProtocol::TYPE_NULL, IgniteWire::unpackUint8(IgniteCacheCodec::encodeNullObject(), 0));
        self::assertSame(1, \strlen(IgniteCacheCodec::encodeNullObject()));
    }
}
