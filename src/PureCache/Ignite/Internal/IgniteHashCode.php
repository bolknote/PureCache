<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Reimplements Java's {@code String.hashCode()} so we can compute the cache
 * identifier that the Ignite thin-client protocol expects in every cache op
 * (it is the 32-bit hash of the cache name, masked to int32 and sign-extended).
 *
 * Ignite walks the UTF-16 code units, but cache names are ASCII in practice and
 * we feed raw bytes here — both interpretations agree for printable ASCII
 * names, which is the only character set our backend uses.
 */
final class IgniteHashCode
{
    private function __construct()
    {
    }

    public static function ofString(string $value): int
    {
        $hash = 0;
        $length = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $hash = (($hash * 31) + \ord($value[$i])) & 0xFFFFFFFF;
        }

        return self::int32SignExtend($hash);
    }

    private static function int32SignExtend(int $unsigned32): int
    {
        if (0 !== ($unsigned32 & 0x80000000)) {
            return $unsigned32 - 0x100000000;
        }

        return $unsigned32;
    }
}
