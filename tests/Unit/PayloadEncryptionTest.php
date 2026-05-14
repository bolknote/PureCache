<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\PayloadEncryption;
use PureCache\MemcachedConstants;

/**
 * Direct unit coverage for the two crypto modes shipped with
 * {@see PayloadEncryption}. {@see EncodingKeyTest} already exercises both
 * modes through the public {@see \PureCache\Internal\ValueCodec} surface;
 * this file targets the raw error paths and bit-layout invariants that are
 * unreachable from the codec wrapper (unknown mode, malformed ciphertext,
 * libmemcached zero-pad arithmetic on exact block multiples).
 */
final class PayloadEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is not loaded');
        }
    }

    public function testEncryptRejectsUnknownMode(): void
    {
        $ctx = $this->buildContextWithMode(999, str_repeat("\0", 16));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown encoding mode');

        PayloadEncryption::encrypt('payload', $ctx);
    }

    public function testDecryptRejectsUnknownMode(): void
    {
        $ctx = $this->buildContextWithMode(999, str_repeat("\0", 16));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown encoding mode');

        PayloadEncryption::decrypt('payload', $ctx);
    }

    public function testLibmemcachedDecryptRejectsEmptyCiphertext(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'k');
        self::assertNotNull($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a multiple of 16 bytes');

        PayloadEncryption::decrypt('', $ctx);
    }

    public function testLibmemcachedDecryptRejectsUnalignedCiphertext(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'k');
        self::assertNotNull($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a multiple of 16 bytes');

        // 17 bytes: longer than a block but not a multiple of 16, exactly the
        // shape that signals corruption or truncation on the wire.
        PayloadEncryption::decrypt(str_repeat("\xff", 17), $ctx);
    }

    public function testLibmemcachedRoundTripPreservesShortPlaintextWithTrailingNulls(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'secret');
        self::assertNotNull($ctx);

        $cipher = PayloadEncryption::encrypt('hi', $ctx);

        self::assertSame(16, \strlen($cipher), 'libmemcached pads to one block');
        self::assertNotSame('hi', $cipher);

        $plain = PayloadEncryption::decrypt($cipher, $ctx);
        self::assertSame('hi', rtrim($plain, "\0"));
        self::assertStringStartsWith('hi', $plain);
    }

    public function testLibmemcachedAlwaysGainsAtLeastOneZeroPadBlock(): void
    {
        // Mirrors libmemcached's `dest = ((src + 16) / 16) * 16` formula: an
        // input already aligned on a 16-byte boundary still grows by a full
        // extra block so the receiver can find the original boundary.
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'secret');
        self::assertNotNull($ctx);

        $cipher = PayloadEncryption::encrypt(str_repeat('A', 16), $ctx);
        self::assertSame(32, \strlen($cipher));

        $cipher = PayloadEncryption::encrypt(str_repeat('A', 17), $ctx);
        self::assertSame(32, \strlen($cipher));

        $cipher = PayloadEncryption::encrypt(str_repeat('A', 31), $ctx);
        self::assertSame(32, \strlen($cipher));

        $cipher = PayloadEncryption::encrypt(str_repeat('A', 32), $ctx);
        self::assertSame(48, \strlen($cipher));
    }

    public function testLibmemcachedEncryptIsDeterministicEcb(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'secret');
        self::assertNotNull($ctx);

        self::assertSame(
            PayloadEncryption::encrypt('same-input', $ctx),
            PayloadEncryption::encrypt('same-input', $ctx),
        );
    }

    public function testAeadDecryptRejectsPayloadShorterThanNonceAndTag(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'k');
        self::assertNotNull($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shorter than nonce+tag');

        // 27 bytes < AEAD_NONCE_LENGTH (12) + AEAD_TAG_LENGTH (16) = 28.
        PayloadEncryption::decrypt(str_repeat("\0", 27), $ctx);
    }

    public function testAeadEncryptPrefixesEachOutputWithFreshNonceAndTag(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'k');
        self::assertNotNull($ctx);

        $plaintext = 'hello-aead';

        $a = PayloadEncryption::encrypt($plaintext, $ctx);
        $b = PayloadEncryption::encrypt($plaintext, $ctx);

        self::assertNotSame($a, $b, 'AEAD must use a fresh random nonce per encryption');
        self::assertGreaterThanOrEqual(12 + 16 + \strlen($plaintext), \strlen($a));
        self::assertNotSame(substr($a, 0, 12), substr($b, 0, 12), 'fresh nonce per call');
    }

    public function testAeadRoundTripRecoversOriginalPlaintextExactly(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'k');
        self::assertNotNull($ctx);

        $plain = 'AEAD has authenticated framing so trailing NULs are not added.';
        $cipher = PayloadEncryption::encrypt($plain, $ctx);

        self::assertSame($plain, PayloadEncryption::decrypt($cipher, $ctx));
    }

    public function testAeadDecryptRejectsTamperedTag(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'k');
        self::assertNotNull($ctx);

        $cipher = PayloadEncryption::encrypt('original', $ctx);
        $tamperedTag = substr_replace($cipher, "\x00", 12, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tag mismatch');

        PayloadEncryption::decrypt($tamperedTag, $ctx);
    }

    public function testAeadDecryptWithDifferentKeyFails(): void
    {
        $writer = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'right');
        $reader = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'wrong');
        self::assertNotNull($writer);
        self::assertNotNull($reader);

        $cipher = PayloadEncryption::encrypt('payload', $writer);

        $this->expectException(\RuntimeException::class);
        PayloadEncryption::decrypt($cipher, $reader);
    }

    /**
     * Bypass {@see EncodingContext::fromUserKey()}'s mode allow-list to feed
     * an "impossible" mode straight into {@see PayloadEncryption} — the public
     * surface refuses such values, but the encrypt/decrypt switch statements
     * still need their {@code default} arms covered for defence-in-depth.
     */
    private function buildContextWithMode(int $mode, string $keyBytes): EncodingContext
    {
        $reflection = new \ReflectionClass(EncodingContext::class);
        $ctx = $reflection->newInstanceWithoutConstructor();

        $modeProperty = $reflection->getProperty('mode');
        $modeProperty->setValue($ctx, $mode);

        $keyProperty = $reflection->getProperty('keyBytes');
        $keyProperty->setValue($ctx, $keyBytes);

        return $ctx;
    }
}
