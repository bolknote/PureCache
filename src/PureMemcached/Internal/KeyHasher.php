<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

/**
 * libmemcached-compatible key hashing (hashkit).
 */
final class KeyHasher
{
    public static function hash(string $key, int $algorithm): int
    {
        return match ($algorithm) {
            MemcachedConstants::HASH_DEFAULT => self::oneAtATime($key),
            MemcachedConstants::HASH_MD5 => self::md5First4($key),
            MemcachedConstants::HASH_CRC => self::crc($key),
            MemcachedConstants::HASH_FNV1_64 => self::fnv164($key),
            MemcachedConstants::HASH_FNV1A_64 => self::fnv1a64($key),
            MemcachedConstants::HASH_FNV1_32 => self::fnv132($key),
            MemcachedConstants::HASH_FNV1A_32 => self::fnv1a32($key),
            MemcachedConstants::HASH_HSIEH => self::hsieh($key),
            MemcachedConstants::HASH_MURMUR => self::murmur2($key),
            default => self::oneAtATime($key),
        };
    }

    private static function toUInt32(int $v): int
    {
        return $v & 0xFFFFFFFF;
    }

    private static function oneAtATime(string $key): int
    {
        $hash = 0;
        $len = \strlen($key);
        for ($i = 0; $i < $len; ++$i) {
            $hash += \ord($key[$i]);
            $hash += $hash << 10;
            $hash ^= (($hash & 0xFFFFFFFF) >> 6) & 0xFFFFFFFF;
            $hash &= 0xFFFFFFFF;
        }

        $hash += $hash << 3;
        $hash ^= (($hash & 0xFFFFFFFF) >> 11) & 0xFFFFFFFF;
        $hash &= 0xFFFFFFFF;
        $hash += $hash << 15;

        return self::toUInt32($hash);
    }

    private static function md5First4(string $key): int
    {
        $b = md5($key, true);

        return \ord($b[0]) | (\ord($b[1]) << 8) | (\ord($b[2]) << 16) | (\ord($b[3]) << 24);
    }

    private static function crc(string $key): int
    {
        return (self::toUInt32(crc32($key)) >> 16) & 0x7FFF;
    }

    private static function fnv164(string $key): int
    {
        return self::uint64HashLow32(hash('fnv164', $key));
    }

    private static function fnv1a64(string $key): int
    {
        return self::uint64HashLow32(hash('fnv1a64', $key));
    }

    private static function fnv132(string $key): int
    {
        return self::uint32HashToPositiveInt(hash('fnv132', $key));
    }

    private static function fnv1a32(string $key): int
    {
        return self::uint32HashToPositiveInt(hash('fnv1a32', $key));
    }

    private static function hsieh(string $key): int
    {
        $len = \strlen($key);
        if (0 === $len) {
            return 0;
        }

        $hash = $len;
        $rem = $len & 3;
        $blocks = $len >> 2;
        $i = 0;

        for ($block = 0; $block < $blocks; ++$block) {
            $hash = self::toUInt32($hash + self::get16bits($key, $i));
            $tmp = self::toUInt32((self::get16bits($key, $i + 2) << 11) ^ $hash);
            $hash = self::toUInt32(($hash << 16) ^ $tmp);
            $i += 4;
            $hash = self::toUInt32($hash + ($hash >> 11));
        }

        switch ($rem) {
            case 3:
                $hash = self::toUInt32($hash + self::get16bits($key, $i));
                $hash ^= self::toUInt32($hash << 16);
                $hash ^= \ord($key[$i + 2]) << 18;
                $hash = self::toUInt32($hash + ($hash >> 11));
                break;

            case 2:
                $hash = self::toUInt32($hash + self::get16bits($key, $i));
                $hash ^= self::toUInt32($hash << 11);
                $hash = self::toUInt32($hash + ($hash >> 17));
                break;

            case 1:
                $hash = self::toUInt32($hash + \ord($key[$i]));
                $hash ^= self::toUInt32($hash << 10);
                $hash = self::toUInt32($hash + ($hash >> 1));
                break;
        }

        $hash ^= self::toUInt32($hash << 3);
        $hash = self::toUInt32($hash + ($hash >> 5));
        $hash ^= self::toUInt32($hash << 4);
        $hash = self::toUInt32($hash + ($hash >> 17));
        $hash ^= self::toUInt32($hash << 25);
        $hash = self::toUInt32($hash + ($hash >> 6));

        return self::toUInt32($hash);
    }

    private static function murmur2(string $key): int
    {
        $len = \strlen($key);
        $remaining = $len;
        $m = 0x5BD1E995;
        $r = 24;
        $h = self::toUInt32(self::mul32(0xDEADBEEF, $len) ^ $len);
        $i = 0;

        while ($remaining >= 4) {
            $k = \ord($key[$i]) | (\ord($key[$i + 1]) << 8) | (\ord($key[$i + 2]) << 16) | (\ord($key[$i + 3]) << 24);
            $k = self::mul32($k, $m);
            $k ^= $k >> $r;
            $k = self::mul32($k, $m);

            $h = self::mul32($h, $m);
            $h ^= $k;
            $h = self::toUInt32($h);

            $i += 4;
            $remaining -= 4;
        }

        switch ($remaining) {
            case 3:
                $h ^= \ord($key[$i + 2]) << 16;
                // no break
            case 2:
                $h ^= \ord($key[$i + 1]) << 8;
                // no break
            case 1:
                $h ^= \ord($key[$i]);
                $h = self::mul32($h, $m);
        }

        $h ^= $h >> 13;
        $h = self::mul32($h, $m);
        $h ^= $h >> 15;

        return self::toUInt32($h);
    }

    private static function get16bits(string $key, int $offset): int
    {
        return \ord($key[$offset]) | (\ord($key[$offset + 1]) << 8);
    }

    private static function uint32HashToPositiveInt(string $hex): int
    {
        return hexdec($hex) & 0xFFFFFFFF;
    }

    private static function uint64HashLow32(string $hex): int
    {
        return hexdec(substr($hex, -8)) & 0xFFFFFFFF;
    }

    private static function mul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        $low = ($a & 0xFFFF) * ($b & 0xFFFF);
        $mid = (($a >> 16) & 0xFFFF) * ($b & 0xFFFF)
            + ($a & 0xFFFF) * (($b >> 16) & 0xFFFF);

        return ($low + (($mid & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
