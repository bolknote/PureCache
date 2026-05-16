<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteReply;
use PureCache\Ignite\Internal\IgniteTransportException;
use PureCache\Ignite\Internal\IgniteWire;

final class IgniteReplyTest extends TestCase
{
    public function testAssertFrameLengthRejectsNegativeAndOversized(): void
    {
        $this->expectException(IgniteTransportException::class);
        IgniteReply::assertFrameLength(-1);

        $this->expectException(IgniteTransportException::class);
        IgniteReply::assertFrameLength(IgniteReply::MAX_FRAME_BYTES + 1);
    }

    public function testReadByteArrayObjectRoundTrip(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_BYTE_ARRAY)
            .IgniteWire::packInt32(3)
            .'abc';

        [$value, $offset] = IgniteReply::readByteArrayObject($body, 0);
        self::assertSame('abc', $value);
        self::assertSame(\strlen($body), $offset);
    }

    public function testReadByteArrayObjectRejectsWrongType(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_STRING);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readByteArrayObject($body, 0);
    }

    public function testReadByteArrayObjectRejectsNegativeLength(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_BYTE_ARRAY).IgniteWire::packInt32(-1);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readByteArrayObject($body, 0);
    }

    public function testReadByteArrayObjectReturnsNullForNullType(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_NULL);
        [$value, $offset] = IgniteReply::readByteArrayObject($body, 0);
        self::assertNull($value);
        self::assertSame(1, $offset);
    }

    public function testReadStringObjectReturnsEmptyForNullType(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_NULL);
        [$value, $offset] = IgniteReply::readStringObject($body, 0);
        self::assertSame('', $value);
        self::assertSame(1, $offset);
    }

    public function testReadStringObjectRejectsNegativeLength(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_STRING).IgniteWire::packInt32(-2);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readStringObject($body, 0);
    }

    public function testReadStringObjectRejectsWrongType(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_BYTE_ARRAY);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readStringObject($body, 0);
    }

    public function testReadDataObjectSupportsByteArray(): void
    {
        $body = pack('C', IgniteProtocol::TYPE_BYTE_ARRAY)
            .IgniteWire::packInt32(2)
            .'xy';
        [$value] = IgniteReply::readDataObject($body, 0);
        self::assertSame('xy', $value);
    }

    public function testReadDataObjectRejectsUnknownType(): void
    {
        $body = pack('C', 0xFF);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readDataObject($body, 0);
    }

    public function testReadBoolParsesZeroAndOne(): void
    {
        self::assertTrue(IgniteReply::readBool(pack('C', 1), 0));
        self::assertFalse(IgniteReply::readBool(pack('C', 0), 0));
    }

    public function testReadBoolRejectsInvalidByte(): void
    {
        $this->expectException(\RuntimeException::class);
        IgniteReply::readBool(pack('C', 2), 0);
    }

    public function testReadDataObjectSupportsIntAndLong(): void
    {
        $intBody = pack('C', IgniteProtocol::TYPE_INT).IgniteWire::packInt32(42);
        [$intVal] = IgniteReply::readDataObject($intBody, 0);
        self::assertSame(42, $intVal);

        $longBody = pack('C', IgniteProtocol::TYPE_LONG).IgniteWire::packInt64(99);
        [$longVal] = IgniteReply::readDataObject($longBody, 0);
        self::assertSame(99, $longVal);
    }

    public function testReadScanPageRejectsNegativeRowCount(): void
    {
        $body = IgniteWire::packInt32(-1);
        $this->expectException(\RuntimeException::class);
        IgniteReply::readScanPage($body, 0);
    }

    public function testReadScanPageParsesKeysAndHasMoreFlag(): void
    {
        $key = pack('C', IgniteProtocol::TYPE_STRING)
            .IgniteWire::packInt32(3)
            .'key';
        $value = pack('C', IgniteProtocol::TYPE_NULL);
        $body = IgniteWire::packInt32(1).$key.$value.pack('C', 1);

        [$keys, $hasMore] = IgniteReply::readScanPage($body, 0);
        self::assertSame(['key'], $keys);
        self::assertTrue($hasMore);
    }
}
