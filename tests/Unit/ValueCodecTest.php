<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;
use PureMemcached\Internal\ValueCodec;

final class ValueCodecTest extends TestCase
{
    public function testPrimitiveRoundTrips(): void
    {
        $cases = [
            'plain text',
            123,
            12.5,
            true,
            false,
        ];

        foreach ($cases as $value) {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                MemcachedClient::SERIALIZER_PHP,
                false,
                MemcachedClient::COMPRESSION_ZLIB,
                3,
                2000,
                1.30,
                -1,
            );

            self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
        }
    }

    public function testExtremeFloatRoundTrips(): void
    {
        $cases = [
            \INF,
            -\INF,
            \NAN,
        ];

        foreach ($cases as $value) {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                MemcachedClient::SERIALIZER_PHP,
                false,
                MemcachedClient::COMPRESSION_ZLIB,
                3,
                2000,
                1.30,
                -1,
            );

            if (\INF === $value) {
                self::assertSame('Infinity', $payload);
            } elseif (-\INF === $value) {
                self::assertSame('-Infinity', $payload);
            } else {
                self::assertSame('NaN', $payload);
            }

            $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
            if (is_nan($value)) {
                self::assertNan($decoded);
            } else {
                self::assertSame($value, $decoded);
            }
        }
    }

    public function testLegacyAndPeclFloatPayloadsDecode(): void
    {
        self::assertSame(\INF, ValueCodec::decode('Infinity', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        self::assertSame(\INF, ValueCodec::decode('INF', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        self::assertSame(-\INF, ValueCodec::decode('-Infinity', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        self::assertSame(-\INF, ValueCodec::decode('-INF', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        self::assertNan(ValueCodec::decode('NaN', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        self::assertNan(ValueCodec::decode('NAN', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
    }

    public function testBoolPayloadUsesPeclFirstByteSemantics(): void
    {
        self::assertTrue(ValueCodec::decode('1', ValueCodec::TYPE_BOOL, MemcachedClient::SERIALIZER_PHP));
        self::assertTrue(ValueCodec::decode('1anything', ValueCodec::TYPE_BOOL, MemcachedClient::SERIALIZER_PHP));
        self::assertFalse(ValueCodec::decode('', ValueCodec::TYPE_BOOL, MemcachedClient::SERIALIZER_PHP));
        self::assertFalse(ValueCodec::decode('0', ValueCodec::TYPE_BOOL, MemcachedClient::SERIALIZER_PHP));
        self::assertFalse(ValueCodec::decode('true', ValueCodec::TYPE_BOOL, MemcachedClient::SERIALIZER_PHP));
    }

    public function testPhpSerializedRoundTrip(): void
    {
        $value = ['a' => 1, 'nested' => ['b' => true]];

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            42,
        );

        self::assertSame(ValueCodec::TYPE_SERIALIZED, ValueCodec::getType($flags));
        self::assertSame(42, ValueCodec::getUserFlags($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testJsonArrayRoundTrip(): void
    {
        $value = ['a' => 1, 'b' => ['c' => true]];

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_JSON_ARRAY,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        self::assertSame(ValueCodec::TYPE_JSON, ValueCodec::getType($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_JSON_ARRAY));
    }

    public function testJsonObjectRoundTrip(): void
    {
        [$payload, $flags] = ValueCodec::encode(
            ['a' => 1],
            MemcachedClient::SERIALIZER_JSON,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_JSON);

        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame(1, $decoded->a);
    }

    public function testCompressedZlibRoundTrip(): void
    {
        if (!\function_exists('gzcompress')) {
            self::markTestSkipped('zlib is not available');
        }

        $value = str_repeat('compressible-', 300);

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZLIB,
            6,
            1,
            1.30,
            -1,
        );

        self::assertTrue(ValueCodec::hasCompression($flags));
        self::assertSame(MemcachedClient::COMPRESSION_ZLIB, ValueCodec::compressionKind($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testPeclCompressionFactorRequiresThirtyPercentSavings(): void
    {
        if (!\function_exists('gzcompress')) {
            self::markTestSkipped('zlib is not available');
        }

        $value = $this->borderlineCompressiblePayload();

        [$payloadWithPeclFactor, $flagsWithPeclFactor] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZLIB,
            6,
            1,
            1.30,
            -1,
        );
        [$payloadWithOldFactor, $flagsWithOldFactor] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZLIB,
            6,
            1,
            1.05,
            -1,
        );

        self::assertFalse(ValueCodec::hasCompression($flagsWithPeclFactor));
        self::assertSame($value, $payloadWithPeclFactor);
        self::assertTrue(ValueCodec::hasCompression($flagsWithOldFactor));
        self::assertNotSame($value, $payloadWithOldFactor);
    }

    public function testUnavailableFastlzCompressionIsSkippedWithoutChangingPayload(): void
    {
        if (\function_exists('fastlz_compress')) {
            self::markTestSkipped('fastlz extension is available');
        }

        if (!\function_exists('gzcompress')) {
            self::markTestSkipped('zlib is not available');
        }

        [$payload, $flags] = ValueCodec::encode(
            str_repeat('fallback-', 300),
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_FASTLZ,
            3,
            1,
            1.30,
            -1,
        );

        self::assertFalse(ValueCodec::hasCompression($flags));
        self::assertSame(str_repeat('fallback-', 300), ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testUnknownCompressionTypeLeavesPayloadUncompressed(): void
    {
        [$payload, $flags] = ValueCodec::encode(
            str_repeat('unknown-compression-', 100),
            MemcachedClient::SERIALIZER_PHP,
            true,
            999,
            3,
            1,
            1.30,
            -1,
        );

        self::assertFalse(ValueCodec::hasCompression($flags));
        self::assertSame(str_repeat('unknown-compression-', 100), ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testInvalidCompressedPayloadFailsFast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid compressed payload');

        ValueCodec::decode('', ValueCodec::COMPRESSED, MemcachedClient::SERIALIZER_PHP);
    }

    public function testInvalidZlibPayloadFailsFast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zlib decompress failed');

        ValueCodec::decode(pack('V', 10).'not-zlib', ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZLIB, MemcachedClient::SERIALIZER_PHP);
    }

    public function testCompressedPayloadLengthMismatchFailsFast(): void
    {
        if (!\function_exists('gzcompress')) {
            self::markTestSkipped('zlib is not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid decompressed payload length');

        ValueCodec::decode(pack('V', 99).gzcompress('short'), ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZLIB, MemcachedClient::SERIALIZER_PHP);
    }

    public function testUnknownStoredTypeReturnsRawPayload(): void
    {
        self::assertSame('raw', ValueCodec::decode('raw', 15, MemcachedClient::SERIALIZER_PHP));
    }

    public function testUnavailableIgbinarySerializerFails(): void
    {
        if (\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('igbinary not available');

        ValueCodec::encode(
            ['a' => 1],
            MemcachedClient::SERIALIZER_IGBINARY,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );
    }

    public function testUnavailableMsgpackSerializerFails(): void
    {
        if (\function_exists('msgpack_pack')) {
            self::markTestSkipped('msgpack is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('msgpack not available');

        ValueCodec::encode(
            ['a' => 1],
            MemcachedClient::SERIALIZER_MSGPACK,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );
    }

    public function testUnavailableZstdCompressionIsSkippedWithoutChangingPayload(): void
    {
        if (\function_exists('zstd_compress')) {
            self::markTestSkipped('zstd is available');
        }

        [$payload, $flags] = ValueCodec::encode(
            str_repeat('zstd-', 300),
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZSTD,
            3,
            1,
            1.30,
            -1,
        );

        self::assertFalse(ValueCodec::hasCompression($flags));
        self::assertSame(str_repeat('zstd-', 300), ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testUnavailableZstdDecompressionFailsFast(): void
    {
        if (\function_exists('zstd_uncompress')) {
            self::markTestSkipped('zstd is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zstd not available');

        ValueCodec::decode(pack('V', 10).'payload', ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZSTD, MemcachedClient::SERIALIZER_PHP);
    }

    private function borderlineCompressiblePayload(): string
    {
        $pool = '';
        for ($i = 0; $i < 1000; ++$i) {
            $pool .= hash('sha256', 'seed-'.$i, true);
        }

        $payload = '';
        for ($i = 0; $i < 2000; ++$i) {
            $payload .= 0 === $i % 3 ? 'A' : $pool[$i];
        }

        return $payload;
    }
}
