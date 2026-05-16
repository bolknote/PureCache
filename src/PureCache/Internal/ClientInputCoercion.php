<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL-shaped coercion for server lists, buckets, and cache_cb expiration refs.
 *
 * @psalm-suppress MixedAssignment
 */
final class ClientInputCoercion
{
    private function __construct()
    {
    }

    public static function coerceString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return '';
    }

    public static function coerceInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * PECL {@code cache_cb} passes expiration by-ref as {@code int}, but PHP does not
     * enforce the type when user callbacks assign floats or numeric strings.
     */
    public static function normalizeCacheCbExpiration(mixed $expiration): int
    {
        if (\is_int($expiration)) {
            return $expiration;
        }

        if (is_numeric($expiration)) {
            return (int) $expiration;
        }

        return 0;
    }

    /**
     * @param array<mixed> $map
     */
    public static function bucketMapValuesAreValid(array $map): bool
    {
        foreach ($map as $value) {
            if (self::coerceInt($value) < 0) {
                return false;
            }
        }

        return true;
    }
}
