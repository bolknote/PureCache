<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteReply;
use PureCache\Ignite\Internal\IgniteTransportException;
use PureCache\Ignite\Internal\IgniteWire;

final class IgniteReplyTest extends TestCase
{
    public function testReadByteArrayObjectReturnsValueAndNextOffset(): void
    {
        $payload = 'payload-bytes';
        $bytes = IgniteCacheCodec::encodeByteArrayObject($payload);

        [$value, $next] = IgniteReply::readByteArrayObject($bytes, 0);

        self::assertSame($payload, $value);
        self::assertSame(\strlen($bytes), $next);
    }

    public function testReadByteArrayObjectNullAdvancesByOneByte(): void
    {
        $bytes = IgniteCacheCodec::encodeNullObject();

        [$value, $next] = IgniteReply::readByteArrayObject($bytes, 0);

        self::assertNull($value);
        self::assertSame(1, $next);
    }

    public function testReadByteArrayObjectRejectsTruncatedLengthPrefix(): void
    {
        $bytes = "\x0c\x00\x00\x00\x10";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        IgniteReply::readByteArrayObject($bytes, 0);
    }

    public function testAssertFrameLengthRejectsOversizedFrames(): void
    {
        $this->expectException(IgniteTransportException::class);
        $this->expectExceptionMessage('exceeds maximum');

        IgniteReply::assertFrameLength(IgniteReply::MAX_FRAME_BYTES + 1);
    }

    #[DataProvider('invalidFrameLengthsProvider')]
    public function testAssertFrameLengthRejectsInvalidLengths(int $length): void
    {
        $this->expectException(IgniteTransportException::class);

        IgniteReply::assertFrameLength($length);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidFrameLengthsProvider(): iterable
    {
        yield 'negative' => [-1];
    }

    public function testReadBoolRejectsNonZeroOneValues(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid bool');

        IgniteReply::readBool("\x02", 0);
    }

    public function testReadScanPageRejectsNegativeRowCount(): void
    {
        $bytes = IgniteWire::packInt32(-1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('negative scan row count');

        IgniteReply::readScanPage($bytes, 0);
    }

    public function testReadScanPageWalksKeysAndValues(): void
    {
        $bytes = IgniteWire::packInt32(2)
            .IgniteCacheCodec::encodeStringObject('a')
            .IgniteCacheCodec::encodeByteArrayObject('va')
            .IgniteCacheCodec::encodeStringObject('b')
            .IgniteCacheCodec::encodeNullObject()
            .IgniteWire::packInt8(0);

        [$keys, $hasMore] = IgniteReply::readScanPage($bytes, 0);

        self::assertSame(['a', 'b'], $keys);
        self::assertFalse($hasMore);
    }
}
