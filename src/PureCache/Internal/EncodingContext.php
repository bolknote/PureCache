<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Frozen, ready-to-use encryption context attached to a {@see ClientCoreState}
 * by {@see \PureCache\AbstractCacheClient::setEncodingKey()}. Carries the
 * algorithm mode plus the pre-derived raw key bytes so the hot encode/decode
 * paths never hash the user-supplied key.
 *
 *  - {@see MemcachedConstants::ENCODING_MODE_LIBMEMCACHED}: {@code $keyBytes}
 *    is 16 bytes ({@code md5($userKey, true)}) and feeds AES-128-ECB with
 *    zero padding for bit-compatibility with libmemcached's
 *    {@code memcached_set_encoding_key}. No flag bit is set on stored
 *    entries.
 *  - {@see MemcachedConstants::ENCODING_MODE_AEAD}: {@code $keyBytes} is
 *    32 bytes ({@code hash('sha256', $userKey, true)}) and feeds
 *    AES-256-GCM with a random per-value nonce. Encrypted entries carry the
 *    {@see ValueCodec::ENCRYPTED_AEAD} marker so unencrypted legacy values
 *    keep round-tripping unchanged.
 */
final readonly class EncodingContext
{
    private function __construct(
        public int $mode,
        public string $keyBytes,
    ) {
    }

    /**
     * Derive an encoding context from a raw user-supplied key.
     *
     * The user key is hashed so callers may pass passphrases of any length;
     * the resulting {@code $keyBytes} is the binary AES key consumed by
     * {@see PayloadEncryption}. Returns {@code null} for an empty user key
     * (PECL's setEncodingKey rejects empty input as
     * {@code MEMCACHED_INVALID_ARGUMENTS}) or for an unknown mode.
     */
    public static function fromUserKey(int $mode, #[\SensitiveParameter] string $userKey): ?self
    {
        if ('' === $userKey) {
            return null;
        }

        return match ($mode) {
            MemcachedConstants::ENCODING_MODE_LIBMEMCACHED => new self($mode, md5($userKey, true)),
            MemcachedConstants::ENCODING_MODE_AEAD => new self($mode, hash('sha256', $userKey, true)),
            default => null,
        };
    }
}
