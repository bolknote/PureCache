<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Wire-level helpers for the cache item wrapper that PureCache stores inside
 * an Ignite {@code byte[]} value, plus the type-tagged key/value framing
 * understood by the thin-client protocol.
 *
 * Wrapper layout (little-endian, fixed 16-byte header):
 *  - int64 cas    — opaque 63-bit positive token (rotated on every successful mutation)
 *  - int32 flags  — memcached F-token (type bits + compression + user flags)
 *  - int32 length — number of payload bytes that follow
 *  - bytes payload
 *
 * Keeping the CAS counter inside the value (rather than relying on Ignite's
 * internal entry version) lets {@code cas()} and friends emit identical
 * tokens to what PECL {@code \Memcached} would expose to PHP code.
 *
 * @internal
 */
final class IgniteCacheCodec
{
    public const int WRAPPER_HEADER_SIZE = 16;

    public static function encodeWrapper(int $cas, int $flags, string $payload): string
    {
        return IgniteWire::packInt64($cas)
            .IgniteWire::packInt32($flags)
            .IgniteWire::packInt32(\strlen($payload))
            .$payload;
    }

    /**
     * @return array{0:int,1:int,2:string}|null
     */
    public static function decodeWrapper(string $bytes): ?array
    {
        if (\strlen($bytes) < self::WRAPPER_HEADER_SIZE) {
            return null;
        }

        $cas = IgniteWire::unpackInt64($bytes, 0);
        $flags = IgniteWire::unpackInt32($bytes, 8);
        $length = IgniteWire::unpackInt32($bytes, 12);
        if ($length < 0 || $length > \strlen($bytes) - self::WRAPPER_HEADER_SIZE) {
            return null;
        }

        $payload = substr($bytes, self::WRAPPER_HEADER_SIZE, $length);

        return [$cas, $flags, $payload];
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
