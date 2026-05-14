<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Wire-level helpers for the cache item wrapper that PureCache stores inside
 * an Ignite {@code byte[]} value, plus the type-tagged key/value framing
 * understood by the thin-client protocol.
 *
 * Wrapper layout (little-endian, fixed 24-byte header):
 *  - int64 cas       — opaque 63-bit positive token (rotated on every successful mutation)
 *  - int32 flags     — memcached F-token (type bits + compression + user flags)
 *  - int64 expireAt  — absolute Unix timestamp (seconds) at which the entry
 *                      becomes stale; {@code 0} means "never expires"
 *  - int32 length    — number of payload bytes that follow
 *  - bytes payload
 *
 * Keeping the CAS counter inside the value (rather than relying on Ignite's
 * internal entry version) lets {@code cas()} and friends emit identical
 * tokens to what PECL {@code \Memcached} would expose to PHP code.
 *
 * The {@code expireAt} field lets {@see \PureCache\Ignite\IgniteClient}
 * implement memcached TTL semantics by doing lazy expiration on read: a
 * decoded wrapper whose {@code expireAt} is in the past is treated as a
 * miss and best-effort deleted. The Ignite thin-client v1.2.0 protocol
 * has no per-key TTL opcode, so the lifecycle has to live inside the
 * value envelope.
 *
 * @internal
 */
final class IgniteCacheCodec
{
    public const int WRAPPER_HEADER_SIZE = 24;

    /** Sentinel {@code expireAt} value meaning "this entry never expires". */
    public const int NEVER_EXPIRES = 0;

    public static function encodeWrapper(int $cas, int $flags, int $expireAt, string $payload): string
    {
        return IgniteWire::packInt64($cas)
            .IgniteWire::packInt32($flags)
            .IgniteWire::packInt64($expireAt)
            .IgniteWire::packInt32(\strlen($payload))
            .$payload;
    }

    /**
     * @return array{0:int,1:int,2:int,3:string}|null tuple of
     *                                                {@code [cas, flags, expireAt, payload]}, or {@code null}
     *                                                when the bytes don't decode (truncated frame or an
     *                                                older/foreign wrapper format)
     */
    public static function decodeWrapper(string $bytes): ?array
    {
        if (\strlen($bytes) < self::WRAPPER_HEADER_SIZE) {
            return null;
        }

        $cas = IgniteWire::unpackInt64($bytes, 0);
        $flags = IgniteWire::unpackInt32($bytes, 8);
        $expireAt = IgniteWire::unpackInt64($bytes, 12);
        $length = IgniteWire::unpackInt32($bytes, 20);
        if ($length < 0 || $length > \strlen($bytes) - self::WRAPPER_HEADER_SIZE) {
            return null;
        }

        $payload = substr($bytes, self::WRAPPER_HEADER_SIZE, $length);

        return [$cas, $flags, $expireAt, $payload];
    }

    public static function encodeStringObject(string $value): string
    {
        return IgniteWire::packInt8(IgniteProtocol::TYPE_STRING)
            .IgniteWire::packInt32(\strlen($value))
            .$value;
    }

    public static function encodeByteArrayObject(string $bytes): string
    {
        return IgniteWire::packInt8(IgniteProtocol::TYPE_BYTE_ARRAY)
            .IgniteWire::packInt32(\strlen($bytes))
            .$bytes;
    }

    public static function encodeNullObject(): string
    {
        return IgniteWire::packInt8(IgniteProtocol::TYPE_NULL);
    }
}
