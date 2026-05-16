<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\PayloadEncryption;
use PureCache\MemcachedConstants;

final class PayloadEncryptionTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is not loaded');
        }
    }

    public function testAeadDecryptRejectsPayloadShorterThanNoncePlusTag(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'short-key');
        self::assertNotNull($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('payload shorter than nonce+tag');

        PayloadEncryption::decrypt(str_repeat("\x00", 20), $ctx);
    }

    public function testAeadEncryptDecryptRoundTrip(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-test-key-material');
        self::assertNotNull($ctx);

        $plain = 'secret payload';
        $cipher = PayloadEncryption::encrypt($plain, $ctx);
        self::assertGreaterThan(\strlen($plain), \strlen($cipher));

        self::assertSame($plain, PayloadEncryption::decrypt($cipher, $ctx));
    }

    public function testLibmemcachedEncryptDecryptRoundTrip(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'libmc-key-16bytes!!');
        self::assertNotNull($ctx);

        $plain = 'padded-plain';
        $cipher = PayloadEncryption::encrypt($plain, $ctx);
        self::assertSame(0, \strlen($cipher) % 16);

        self::assertSame($plain, rtrim(PayloadEncryption::decrypt($cipher, $ctx), "\0"));
    }

    public function testLibmemcachedDecryptRejectsNonBlockAlignedPayload(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'libmc-key-16bytes!!');
        self::assertNotNull($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a multiple of 16 bytes');
        PayloadEncryption::decrypt('short', $ctx);
    }

    public function testEncryptAndDecryptRejectUnknownEncodingMode(): void
    {
        $ctx = $this->encodingContext(99, str_repeat('x', 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown encoding mode');
        PayloadEncryption::encrypt('plain', $ctx);
    }

    public function testDecryptRejectsUnknownEncodingMode(): void
    {
        $ctx = $this->encodingContext(99, str_repeat('x', 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown encoding mode');
        PayloadEncryption::decrypt('cipher', $ctx);
    }

    public function testAeadDecryptFailsOnCorruptCiphertext(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'aead-test-key-material');
        self::assertNotNull($ctx);

        $cipher = PayloadEncryption::encrypt('plain', $ctx);
        $cipher = substr_replace($cipher, $cipher[20] ^ "\x01", 20, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('aes-256-gcm decrypt failed');
        PayloadEncryption::decrypt($cipher, $ctx);
    }

    private function encodingContext(int $mode, string $keyBytes): EncodingContext
    {
        $ref = new \ReflectionClass(EncodingContext::class);
        $ctx = $ref->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EncodingContext::class, 'mode'))->setValue($ctx, $mode);
        (new \ReflectionProperty(EncodingContext::class, 'keyBytes'))->setValue($ctx, $keyBytes);

        return $ctx;
    }
}
