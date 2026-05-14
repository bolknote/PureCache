<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Pure-PHP implementation of the two encryption modes accepted by
 * {@see \PureCache\AbstractCacheClient::setEncodingKey()}. Both modes wrap an
 * already-serialized-and-(optionally-)compressed payload — encryption runs
 * after compression on write and before decompression on read so the
 * compression ratio is computed on plaintext.
 *
 * Throws {@see \RuntimeException} on any cryptographic failure (missing
 * {@code ext-openssl}, bad ciphertext length, GCM tag mismatch); callers map
 * those into {@code RES_PAYLOAD_FAILURE} so the failure is observable through
 * the PECL surface.
 */
final class PayloadEncryption
{
    private const string LIBMEMCACHED_CIPHER = 'aes-128-ecb';

    private const string AEAD_CIPHER = 'aes-256-gcm';

    private const int AEAD_NONCE_LENGTH = 12;

    private const int AEAD_TAG_LENGTH = 16;

    private const int LIBMEMCACHED_BLOCK = 16;

    private function __construct()
    {
    }

    public static function encrypt(string $plaintext, EncodingContext $ctx): string
    {
        return match ($ctx->mode) {
            MemcachedConstants::ENCODING_MODE_LIBMEMCACHED => self::encryptLibmemcached($plaintext, $ctx->keyBytes),
            MemcachedConstants::ENCODING_MODE_AEAD => self::encryptAead($plaintext, $ctx->keyBytes),
            default => throw new \RuntimeException('unknown encoding mode'),
        };
    }

    public static function decrypt(string $ciphertext, EncodingContext $ctx): string
    {
        return match ($ctx->mode) {
            MemcachedConstants::ENCODING_MODE_LIBMEMCACHED => self::decryptLibmemcached($ciphertext, $ctx->keyBytes),
            MemcachedConstants::ENCODING_MODE_AEAD => self::decryptAead($ciphertext, $ctx->keyBytes),
            default => throw new \RuntimeException('unknown encoding mode'),
        };
    }

    /**
     * libmemcached pads to a multiple of 16 bytes and *always* appends at
     * least one zero byte ({@code dest = ((src + 16) / 16) * 16}), so an
     * already block-aligned input still gains a full extra block of zeros.
     * We reproduce that buffer layout exactly so values written by a real
     * libmemcached client (with the same user key) decrypt byte-for-byte.
     */
    private static function encryptLibmemcached(string $plaintext, string $key16): string
    {
        $srcLen = \strlen($plaintext);
        $padded = self::libmemcachedPad($plaintext, $srcLen);

        $cipher = openssl_encrypt(
            $padded,
            self::LIBMEMCACHED_CIPHER,
            $key16,
            \OPENSSL_RAW_DATA | \OPENSSL_ZERO_PADDING,
        );

        if (!\is_string($cipher)) {
            throw new \RuntimeException('aes-128-ecb encrypt failed: '.self::lastOpensslError());
        }

        return $cipher;
    }

    /**
     * libmemcached has no length header on encrypted payloads and returns the
     * buffer with trailing zero bytes intact — the same compromise we inherit
     * here. Downstream decompression strips its own framing (gzip/zstd carry
     * their own length), and serializers either ignore or reject trailing
     * NULs in exactly the same way they would against a real libmemcached
     * setup.
     */
    private static function decryptLibmemcached(string $ciphertext, string $key16): string
    {
        if ('' === $ciphertext || 0 !== (\strlen($ciphertext) % self::LIBMEMCACHED_BLOCK)) {
            throw new \RuntimeException('aes-128-ecb decrypt: payload not a multiple of 16 bytes');
        }

        $plain = openssl_decrypt(
            $ciphertext,
            self::LIBMEMCACHED_CIPHER,
            $key16,
            \OPENSSL_RAW_DATA | \OPENSSL_ZERO_PADDING,
        );

        if (!\is_string($plain)) {
            throw new \RuntimeException('aes-128-ecb decrypt failed: '.self::lastOpensslError());
        }

        return $plain;
    }

    private static function encryptAead(string $plaintext, string $key32): string
    {
        $nonce = random_bytes(self::AEAD_NONCE_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::AEAD_CIPHER,
            $key32,
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::AEAD_TAG_LENGTH,
        );

        if (!\is_string($cipher) || self::AEAD_TAG_LENGTH !== \strlen($tag)) {
            throw new \RuntimeException('aes-256-gcm encrypt failed: '.self::lastOpensslError());
        }

        return $nonce.$tag.$cipher;
    }

    private static function decryptAead(string $payload, string $key32): string
    {
        $minLen = self::AEAD_NONCE_LENGTH + self::AEAD_TAG_LENGTH;
        if (\strlen($payload) < $minLen) {
            throw new \RuntimeException('aes-256-gcm decrypt: payload shorter than nonce+tag');
        }

        $nonce = substr($payload, 0, self::AEAD_NONCE_LENGTH);
        $tag = substr($payload, self::AEAD_NONCE_LENGTH, self::AEAD_TAG_LENGTH);
        $cipher = substr($payload, $minLen);

        $plain = openssl_decrypt(
            $cipher,
            self::AEAD_CIPHER,
            $key32,
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
        );

        if (!\is_string($plain)) {
            throw new \RuntimeException('aes-256-gcm decrypt failed (tag mismatch or corrupt payload)');
        }

        return $plain;
    }

    private static function libmemcachedPad(string $plaintext, int $srcLen): string
    {
        $blocks = intdiv($srcLen + self::LIBMEMCACHED_BLOCK, self::LIBMEMCACHED_BLOCK);
        $destLen = $blocks * self::LIBMEMCACHED_BLOCK;

        return str_pad($plaintext, $destLen, "\0");
    }

    private static function lastOpensslError(): string
    {
        $msg = '';
        while (\is_string($err = openssl_error_string())) {
            $msg = $err;
        }

        return '' !== $msg ? $msg : 'unknown openssl error';
    }
}
