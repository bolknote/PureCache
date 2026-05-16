<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\ValueCodec;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

final class ValueCodecTest extends TestCase
{
    public function testDecodeNeverSegfaultsOnRandomPayloads(): void
    {
        $this->expectNotToPerformAssertions();

        for ($i = 0; $i < 64; ++$i) {
            try {
                ValueCodec::decode(
                    random_bytes(random_int(1, 48)),
                    random_int(0, 0xFFFF),
                    MemcachedClient::SERIALIZER_PHP,
                );
            } catch (\Throwable) {
            }
        }
    }

    public function testEncodeAppliesUserFlagsWhenNonNegative(): void
    {
        [$payload, $flags] = ValueCodec::encode(
            'v',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            7,
        );

        self::assertNotSame(0, $flags & ValueCodec::MASK_USER);
        self::assertSame(7, ValueCodec::getUserFlags($flags));
        self::assertSame('v', ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testJsonArraySerializerDecodesToPhpArray(): void
    {
        $value = ['k' => ['nested' => 1]];

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

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_JSON_ARRAY);
        self::assertIsArray($decoded);
        self::assertSame($value, $decoded);
    }

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
            // IEEE NaN without the NAN constant (Psalm 6.16 + PHP 8.5 + NAN literals).
            acos(2.0),
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

    public function testPhpSerializedClassesAreSafeByDefault(): void
    {
        $value = new \DateTimeImmutable('2026-05-14T12:00:00Z');
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

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }

    public function testIgbinarySerializedClassesAreSafeByDefault(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary extension not available');
        }

        $value = new \DateTimeImmutable('2026-05-14T12:00:00Z');
        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_IGBINARY,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        if ($this->igbinarySupportsAllowedClassesOption()) {
            $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_IGBINARY);
            self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
        } else {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('igbinary object deserialization blocked');
            ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_IGBINARY);
        }
    }

    public function testIgbinarySerializedClassesRehydrateOnOptIn(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary extension not available');
        }

        $value = new \DateTimeImmutable('2026-05-14T12:00:00Z');
        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_IGBINARY,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_IGBINARY, true);
        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
    }

    private function igbinarySupportsAllowedClassesOption(): bool
    {
        if (!\function_exists('igbinary_unserialize')) {
            return false;
        }

        try {
            return (new \ReflectionFunction('igbinary_unserialize'))->getNumberOfParameters() >= 2;
        } catch (\ReflectionException) {
            return false;
        }
    }

    public function testPhpSerializedClassesRehydrateOnOptIn(): void
    {
        $value = new \DateTimeImmutable('2026-05-14T12:00:00Z');
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

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP, true);
        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame($value->getTimestamp(), $decoded->getTimestamp());
    }

    public function testFloatRoundTripsUnderEuropeanLocale(): void
    {
        $previous = setlocale(\LC_NUMERIC, '0');

        try {
            $applied = setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany.UTF-8');
            if (false === $applied) {
                self::markTestSkipped('de_DE locale not available on this host');
            }

            [$payload, $flags] = ValueCodec::encode(
                3.1415926535,
                MemcachedClient::SERIALIZER_PHP,
                false,
                MemcachedClient::COMPRESSION_ZLIB,
                3,
                2000,
                1.30,
                -1,
            );

            self::assertStringNotContainsString(',', $payload);

            $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
            self::assertSame(3.1415926535, $decoded);
        } finally {
            if (\is_string($previous)) {
                setlocale(\LC_NUMERIC, $previous);
            }
        }
    }

    public function testDecodeDoubleHonoursLocaleWhenNumericLocaleIsNotC(): void
    {
        $previous = setlocale(\LC_NUMERIC, '0');

        try {
            $applied = setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany.UTF-8');
            if (false === $applied) {
                self::markTestSkipped('de_DE locale not available on this host');
            }

            self::assertSame(2.5, ValueCodec::decode('2.5', ValueCodec::TYPE_DOUBLE, MemcachedClient::SERIALIZER_PHP));
        } finally {
            if (\is_string($previous)) {
                setlocale(\LC_NUMERIC, $previous);
            }
        }
    }

    public function testHasAeadEncryptionReflectsInternalFlagBit(): void
    {
        self::assertFalse(ValueCodec::hasAeadEncryption(0));
        self::assertTrue(ValueCodec::hasAeadEncryption(ValueCodec::ENCRYPTED_AEAD));
    }

    public function testDecodeRejectsAeadPayloadWithoutEncodingContext(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is not available');
        }

        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-secret');
        self::assertNotNull($ctx);

        [$payload, $flags] = ValueCodec::encode(
            'plain',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        self::assertTrue(ValueCodec::hasAeadEncryption($flags));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('encrypted payload (AEAD) but no matching encoding key configured');

        ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
    }

    public function testLibmemcachedEncryptedArrayStripsTrailingNullPaddingOnDecode(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is not available');
        }

        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'wire-secret');
        self::assertNotNull($ctx);

        $value = ['nested' => [1, 2, 3]];
        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP, false, $ctx));
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

    public function testIgbinaryRoundTripWhenExtensionIsLoaded(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary is not available');
        }

        $value = ['a' => 1, 'nested' => ['b' => true, 'c' => null]];

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_IGBINARY,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        self::assertSame(ValueCodec::TYPE_IGBINARY, ValueCodec::getType($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_IGBINARY));
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

        $compressed = gzcompress('short');
        self::assertNotFalse($compressed);
        ValueCodec::decode(pack('V', 99).$compressed, ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZLIB, MemcachedClient::SERIALIZER_PHP);
    }

    public function testUnknownStoredTypeReturnsRawPayload(): void
    {
        self::assertSame('raw', ValueCodec::decode('raw', 15, MemcachedClient::SERIALIZER_PHP));
    }

    public function testUnavailableIgbinarySerializerFails(): void
    {
        if (\function_exists('igbinary_serialize')) {
            [$payload, $flags] = ValueCodec::encode(
                ['a' => 1],
                MemcachedClient::SERIALIZER_IGBINARY,
                false,
                MemcachedClient::COMPRESSION_ZLIB,
                3,
                2000,
                1.30,
                -1,
            );

            self::assertSame(['a' => 1], ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_IGBINARY));

            return;
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

    public function testFastlzCompressionRoundTripWhenExtensionIsLoaded(): void
    {
        if (!\function_exists('fastlz_compress') || !\function_exists('fastlz_decompress')) {
            self::markTestSkipped('fastlz is not available');
        }

        $value = str_repeat('fastlz-', 250);

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_FASTLZ,
            3,
            1,
            1.30,
            -1,
        );

        self::assertTrue(ValueCodec::hasCompression($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testZstdCompressionRoundTripWhenExtensionIsLoaded(): void
    {
        if (!\function_exists('zstd_compress') || !\function_exists('zstd_uncompress')) {
            self::markTestSkipped('zstd is not available');
        }

        $value = str_repeat('zstd-payload-', 200);

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZSTD,
            3,
            1,
            1.30,
            -1,
        );

        self::assertTrue(ValueCodec::hasCompression($flags));
        self::assertSame(MemcachedClient::COMPRESSION_ZSTD, ValueCodec::compressionKind($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP));
    }

    public function testMsgpackRoundTripWhenExtensionIsLoaded(): void
    {
        if (!\function_exists('msgpack_pack') || !\function_exists('msgpack_unpack')) {
            self::markTestSkipped('msgpack is not available');
        }

        $value = ['n' => 42, 'nested' => ['ok' => true]];

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_MSGPACK,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        self::assertSame(ValueCodec::TYPE_MSGPACK, ValueCodec::getType($flags));
        self::assertSame($value, ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_MSGPACK));
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

    public function testUnavailableFastlzDecompressionFailsFast(): void
    {
        if (\function_exists('fastlz_decompress')) {
            self::markTestSkipped('fastlz is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fastlz not available');

        ValueCodec::decode(pack('V', 10).'payload', ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_FASTLZ, MemcachedClient::SERIALIZER_PHP);
    }

    public function testCorruptZstdCompressedPayloadFailsDecompression(): void
    {
        if (!\function_exists('zstd_uncompress')) {
            self::markTestSkipped('zstd is not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zstd decompress failed');

        ValueCodec::decode(
            pack('V', 5).'not-zstd',
            ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZSTD,
            MemcachedClient::SERIALIZER_PHP,
        );
    }

    public function testCorruptFastlzCompressedPayloadFailsDecompression(): void
    {
        if (!\function_exists('fastlz_decompress')) {
            self::markTestSkipped('fastlz is not available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fastlz decompress failed');

        ValueCodec::decode(
            pack('V', 5).'not-fastlz',
            ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_FASTLZ,
            MemcachedClient::SERIALIZER_PHP,
        );
    }

    public function testCompressedPayloadUnderFourBytesFailsFast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid compressed payload');

        ValueCodec::decode('abc', ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZLIB, MemcachedClient::SERIALIZER_PHP);
    }

    public function testUnavailableMsgpackDeserializationFailsFast(): void
    {
        if (\function_exists('msgpack_unpack')) {
            self::markTestSkipped('msgpack is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('msgpack not available');

        ValueCodec::decode('ignored', ValueCodec::TYPE_MSGPACK, MemcachedClient::SERIALIZER_MSGPACK);
    }

    public function testUnavailableIgbinaryDeserializationFailsFast(): void
    {
        if (\function_exists('igbinary_unserialize')) {
            self::markTestSkipped('igbinary is available');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('igbinary not available');

        ValueCodec::decode('ignored', ValueCodec::TYPE_IGBINARY, MemcachedClient::SERIALIZER_IGBINARY);
    }

    public function testInvalidPhpSerializedPayloadFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('php unserialize failed');

        ValueCodec::decode('not-a-serialized-payload', ValueCodec::TYPE_SERIALIZED, MemcachedClient::SERIALIZER_PHP);
    }

    public function testPhpSerializedFalseDecodesAsBooleanFalse(): void
    {
        // 'b:0;' deserializes to false; we must NOT treat that as a failure
        // (PECL doesn't), otherwise a legitimately-stored false value would
        // be reported as a missing key.
        self::assertFalse(ValueCodec::decode('b:0;', ValueCodec::TYPE_SERIALIZED, MemcachedClient::SERIALIZER_PHP));
    }

    public function testSetCompressionFlagsTogglesCorrectBitsForEachKnownType(): void
    {
        $known = [
            MemcachedClient::COMPRESSION_ZLIB => ValueCodec::COMPRESSION_ZLIB,
            MemcachedClient::COMPRESSION_FASTLZ => ValueCodec::COMPRESSION_FASTLZ,
            MemcachedClient::COMPRESSION_ZSTD => ValueCodec::COMPRESSION_ZSTD,
        ];

        foreach ($known as $type => $bit) {
            $flags = 0;
            ValueCodec::setCompressionFlags($flags, $type);

            self::assertTrue(ValueCodec::hasCompression($flags));
            self::assertSame($bit, $flags & $bit);
            // Make sure switching to another type clears the previously-set bits
            // so we don't accidentally OR multiple compression algorithms.
            ValueCodec::setCompressionFlags($flags, $type);
            self::assertSame($bit, $flags & (ValueCodec::COMPRESSION_ZLIB | ValueCodec::COMPRESSION_FASTLZ | ValueCodec::COMPRESSION_ZSTD));
        }
    }

    public function testSetCompressionFlagsDefaultsToZlibForUnknownType(): void
    {
        $flags = 0;
        ValueCodec::setCompressionFlags($flags, 999);

        self::assertTrue(ValueCodec::hasCompression($flags));
        self::assertSame(MemcachedClient::COMPRESSION_ZLIB, ValueCodec::compressionKind($flags));
    }

    public function testCompressionKindReportsFastlzWhenItsFlagBitIsSet(): void
    {
        // Direct probe of the compressionKind FastLZ branch, which the
        // ZLIB-default round-trips and the zstd-priority test don't exercise.
        $flags = ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_FASTLZ;

        self::assertSame(MemcachedClient::COMPRESSION_FASTLZ, ValueCodec::compressionKind($flags));
    }

    public function testCompressionKindPrefersZstdOverZlibAndFastlzWhenMultipleBitsAreSet(): void
    {
        // The lookup is priority-based (zstd > fastlz > zlib) so that a value
        // accidentally tagged with two algorithm bits doesn't return zlib and
        // get decompressed with the wrong codec.
        $flags = ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZSTD | ValueCodec::COMPRESSION_FASTLZ | ValueCodec::COMPRESSION_ZLIB;

        self::assertSame(MemcachedClient::COMPRESSION_ZSTD, ValueCodec::compressionKind($flags));
    }

    public function testDoubleRoundTripPreservesValueUnderNonCLocale(): void
    {
        $previous = setlocale(\LC_NUMERIC, '0');
        if (!\is_string($previous)) {
            self::markTestSkipped('setlocale unavailable');
        }

        $restored = setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'deu_DE.UTF-8', 'deu_DE');
        if (false === $restored) {
            setlocale(\LC_NUMERIC, $previous);

            self::markTestSkipped('de_DE locale not available');
        }

        try {
            $value = 1.5;
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
        } finally {
            setlocale(\LC_NUMERIC, $previous);
        }
    }

    public function testIgbinaryUnserializeOptionsSupportIsCached(): void
    {
        $method = new \ReflectionMethod(ValueCodec::class, 'igbinaryAcceptsUnserializeOptions');

        $first = $method->invoke(null);
        $second = $method->invoke(null);

        self::assertSame($first, $second);
        self::assertIsBool($first);
    }

    public function testCompressPayloadZstdFailureThrowsWhenExtensionReturnsFalse(): void
    {
        if (!\function_exists('zstd_compress')) {
            self::markTestSkipped('zstd is not available');
        }

        $method = new \ReflectionMethod(ValueCodec::class, 'compressPayload');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zstd compress failed');
        $method->invoke(null, str_repeat('x', 100), MemcachedClient::COMPRESSION_ZSTD, 99);
    }

    public function testDecompressPayloadZlibFailureThrowsOnGarbage(): void
    {
        $method = new \ReflectionMethod(ValueCodec::class, 'decompressPayload');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zlib decompress failed');
        $method->invoke(null, 'not-zlib', MemcachedClient::COMPRESSION_ZLIB);
    }

    public function testJsonSerializerRoundTrip(): void
    {
        $value = (object) ['k' => 'v', 'n' => 2];

        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_JSON,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_JSON);
        self::assertSame(json_encode($value), json_encode($decoded));
    }

    public function testDecodeEncryptedPayloadWithoutContextFails(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is not available');
        }

        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'key');
        self::assertNotNull($ctx);
        [$payload, $flags] = ValueCodec::encode(
            'secret',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no matching encoding key');
        ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
    }

    public function testRandomGarbageDecodeDoesNotCrash(): void
    {
        $failures = 0;
        for ($i = 0; $i < 32; ++$i) {
            $payload = random_bytes(random_int(1, 64));
            $flags = ValueCodec::TYPE_SERIALIZED | ValueCodec::COMPRESSED | ValueCodec::COMPRESSION_ZLIB;

            try {
                ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
            } catch (\RuntimeException) {
                ++$failures;
            }
        }

        self::assertSame(32, $failures);
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
