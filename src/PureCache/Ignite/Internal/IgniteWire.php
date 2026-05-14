<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Endian-correct primitives used by both request encoding and response decoding.
 *
 * Ignite's binary client protocol is little-endian for all multi-byte integers.
 * PHP's {@code pack()} only provides unsigned LE formats, so we sign-extend
 * manually after every read.
 *
 * Operating on 64-bit PHP is a hard requirement (composer.json pins PHP 8.3).
 */
final class IgniteWire
{
    private function __construct()
    {
    }

    public static function packInt8(int $value): string
    {
        return \chr($value & 0xFF);
    }

    public static function packInt16(int $value): string
    {
        return pack('v', $value & 0xFFFF);
    }

    public static function packInt32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    public static function packInt64(int $value): string
    {
        return pack('P', $value);
    }

    public static function unpackInt8(string $bytes, int $offset): int
    {
        $byte = \ord($bytes[$offset]);
        if (0 !== ($byte & 0x80)) {
            return $byte - 0x100;
        }

        return $byte;
    }

    public static function unpackUint8(string $bytes, int $offset): int
    {
        return \ord($bytes[$offset]);
    }

    public static function unpackInt16(string $bytes, int $offset): int
    {
        $unsigned = self::unpackOne('v', substr($bytes, $offset, 2));
        if ($unsigned >= 0x8000) {
            return $unsigned - 0x10000;
        }

        return $unsigned;
    }

    public static function unpackInt32(string $bytes, int $offset): int
    {
        $unsigned = self::unpackOne('V', substr($bytes, $offset, 4));
        if ($unsigned >= 0x80000000) {
            return $unsigned - 0x100000000;
        }

        return $unsigned;
    }

    public static function unpackInt64(string $bytes, int $offset): int
    {
        return self::unpackOne('P', substr($bytes, $offset, 8));
    }

    private static function unpackOne(string $format, string $bytes): int
    {
        $unpacked = unpack($format, $bytes);
        if (false === $unpacked || !isset($unpacked[1]) || !\is_int($unpacked[1])) {
            throw new \RuntimeException('failed to unpack '.$format);
        }

        return $unpacked[1];
    }
}
