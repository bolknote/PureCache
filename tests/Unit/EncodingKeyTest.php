<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\ValueCodec;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;
use PureCache\Redis\RedisClient;

/**
 * setEncodingKey() and the underlying value-codec encryption layer are
 * pure-PHP, so they are exercised entirely off the wire here. Roundtrip
 * tests cover the libmemcached bit-compatible mode (AES-128-ECB, MD5 key,
 * zero padding) and the modern AEAD mode (AES-256-GCM with random nonce
 * and tag), plus the error paths every client surface needs to keep
 * stable.
 */
final class EncodingKeyTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is not loaded');
        }
    }

    public function testHaveEncodingTracksOpensslExtension(): void
    {
        self::assertTrue(MemcachedConstants::HAVE_ENCODING);
        self::assertTrue(MemcachedClient::HAVE_ENCODING);
        self::assertTrue(RedisClient::HAVE_ENCODING);
        self::assertSame(\extension_loaded('openssl'), MemcachedConstants::HAVE_ENCODING);
    }

    public function testSetEncodingKeyEmptyStringIsInvalidArguments(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setEncodingKey(''));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testDefaultModeIsLibmemcachedCompat(): void
    {
        $client = new MemcachedClient();

        self::assertSame(
            MemcachedClient::ENCODING_MODE_LIBMEMCACHED,
            $client->getOption(MemcachedClient::OPT_ENCODING_MODE),
        );
        self::assertTrue($client->setEncodingKey('s3cret'));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    /**
     * The cases here all survive libmemcached's zero-pad-everything wire
     * format: PHP-serialized arrays carry their own length, primitive ints
     * and floats are reparsed by {@see ValueCodec::deserializePayload()},
     * and the long compressible string takes the compression branch (4-byte
     * length header + zlib frame) so trailing zeros after the encrypted
     * block are ignored on decompression. Pure short strings get the
     * libmemcached-style trailing NULs — see
     * {@see testLibmemcachedShortStringDecodesWithLibmemcachedTrailingNulls()}.
     */
    public function testLibmemcachedModeRoundTripsCommonValues(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 's3cret');
        self::assertNotNull($ctx);

        $cases = [
            42,
            3.14,
            ['nested' => ['a' => 1, 'b' => [true, false, null]]],
            str_repeat('compressible-', 200),
        ];

        foreach ($cases as $value) {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                MemcachedClient::SERIALIZER_PHP,
                true,
                MemcachedClient::COMPRESSION_ZLIB,
                3,
                64,
                1.05,
                -1,
                $ctx,
            );

            self::assertFalse(ValueCodec::hasAeadEncryption($flags), 'libmemcached mode must not set AEAD flag');
            $decoded = ValueCodec::decode(
                $payload,
                $flags,
                MemcachedClient::SERIALIZER_PHP,
                false,
                $ctx,
            );

            if (\is_float($value)) {
                self::assertSame($value, $decoded);
                continue;
            }

            self::assertSame($value, $decoded);
        }
    }

    public function testLibmemcachedShortStringDecodesWithLibmemcachedTrailingNulls(): void
    {
        // libmemcached pads every encrypted buffer to a 16-byte boundary with
        // zero bytes and provides no out-of-band length, so when an
        // uncompressed string round-trips through ECB we get the plaintext
        // back with trailing NULs — exactly what a real libmemcached client
        // would observe. We pin this so the parity behaviour is intentional
        // and not a regression to fix.
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'k');
        self::assertNotNull($ctx);

        [$payload, $flags] = ValueCodec::encode(
            'abc',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        $decoded = ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP, false, $ctx);

        self::assertIsString($decoded);
        self::assertSame('abc', rtrim($decoded, "\0"));
        self::assertStringStartsWith('abc', $decoded);
        self::assertSame(16, \strlen($decoded));
    }

    public function testLibmemcachedCiphertextIsBlockAlignedAndDiffersFromPlaintext(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'secret');
        self::assertNotNull($ctx);

        $plaintext = 'abc';
        [$payload] = ValueCodec::encode(
            $plaintext,
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        self::assertNotSame($plaintext, $payload);
        self::assertSame(0, \strlen($payload) % 16, 'libmemcached ECB output is block-aligned');
        // libmemcached's `((srcLen + 16) / 16) * 16` formula: 3 → 16 bytes.
        self::assertSame(16, \strlen($payload));
    }

    public function testLibmemcachedAddsFullExtraBlockForExactBlockMultiplePlaintext(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'secret');
        self::assertNotNull($ctx);

        // 16-byte plaintext (string type, no compression) must round up to
        // 32 bytes after encryption to match libmemcached's padding formula:
        // it always appends at least one zero byte even when the input is
        // already block-aligned.
        $plaintext = str_repeat('A', 16);
        [$payload] = ValueCodec::encode(
            $plaintext,
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        self::assertSame(32, \strlen($payload));
    }

    public function testLibmemcachedModeIsDeterministicEcb(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'k');
        self::assertNotNull($ctx);

        $args = [
            'same input',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        ];

        [$a] = ValueCodec::encode(...$args);
        [$b] = ValueCodec::encode(...$args);

        self::assertSame($a, $b, 'AES-128-ECB is deterministic; libmemcached compat must reflect that');
    }

    public function testLibmemcachedDifferentKeysProduceDifferentCiphertexts(): void
    {
        $a = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'alpha');
        $b = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'bravo');
        self::assertNotNull($a);
        self::assertNotNull($b);

        $args = [
            'hello',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        ];

        [$cipherA] = ValueCodec::encode(...[...$args, $a]);
        [$cipherB] = ValueCodec::encode(...[...$args, $b]);

        self::assertNotSame($cipherA, $cipherB);
    }

    public function testAeadModeMarksFlagAndRoundTrips(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-key');
        self::assertNotNull($ctx);

        $value = ['nested' => ['secret' => 'value', 'n' => 42]];
        [$payload, $flags] = ValueCodec::encode(
            $value,
            MemcachedClient::SERIALIZER_PHP,
            true,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            64,
            1.05,
            -1,
            $ctx,
        );

        self::assertTrue(ValueCodec::hasAeadEncryption($flags));
        self::assertSame(
            $value,
            ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP, false, $ctx),
        );
    }

    public function testAeadModeIsNonDeterministic(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-key');
        self::assertNotNull($ctx);

        $args = [
            'hello',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        ];

        [$a] = ValueCodec::encode(...$args);
        [$b] = ValueCodec::encode(...$args);

        self::assertNotSame($a, $b, 'AEAD must use a fresh random nonce per encryption');
    }

    public function testAeadDecodeWithoutKeyThrows(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-key');
        self::assertNotNull($ctx);

        [$payload, $flags] = ValueCodec::encode(
            'hello',
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
        ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP);
    }

    public function testAeadDecodeWithWrongKeyFailsAuthentication(): void
    {
        $writer = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'right');
        $reader = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'wrong');
        self::assertNotNull($writer);
        self::assertNotNull($reader);

        [$payload, $flags] = ValueCodec::encode(
            'hello',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $writer,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tag mismatch');
        ValueCodec::decode($payload, $flags, MemcachedClient::SERIALIZER_PHP, false, $reader);
    }

    public function testAeadTamperedCiphertextFailsAuthentication(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-key');
        self::assertNotNull($ctx);

        [$payload, $flags] = ValueCodec::encode(
            'hello',
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
            $ctx,
        );

        // Tamper the GCM auth tag (bytes 12–27), not ciphertext tail — last-byte
        // replace is a no-op when that byte is already 0x00.
        $tampered = substr_replace($payload, $payload[12] ^ "\x01", 12, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tag mismatch');
        ValueCodec::decode($tampered, $flags, MemcachedClient::SERIALIZER_PHP, false, $ctx);
    }

    public function testSwitchingModeViaSetOptionClearsExistingKey(): void
    {
        $client = new MemcachedClient();

        self::assertTrue($client->setEncodingKey('first'));
        self::assertTrue(
            $client->setOption(MemcachedClient::OPT_ENCODING_MODE, MemcachedClient::ENCODING_MODE_AEAD),
        );

        $encoding = (new \ReflectionMethod($client, 'encodingContext'))->invoke($client);

        self::assertNull($encoding, 'changing encoding mode must invalidate the previously-set key');
    }

    public function testAppendWithEncodingKeySetReturnsNotStoredWithoutHittingTheWire(): void
    {
        $client = new MemcachedClient();
        // Compression is rejected for append/prepend before the encoding
        // check fires; disable it so the test exercises the encoding-key
        // branch on its own.
        self::assertTrue($client->setOption(MemcachedClient::OPT_COMPRESSION, false));
        self::assertTrue($client->setEncodingKey('secret'));

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            if (\E_USER_WARNING === $errno) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            self::assertFalse($client->append('key', 'tail'));
            self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());
            self::assertContains('cannot append/prepend with encoding key set', $warnings);
        } finally {
            restore_error_handler();
        }
    }

    public function testInvalidEncodingModeIsRejected(): void
    {
        $client = new MemcachedClient();
        self::assertFalse($client->setOption(MemcachedClient::OPT_ENCODING_MODE, 999));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }
}
