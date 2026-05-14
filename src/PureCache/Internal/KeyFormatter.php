<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Key formatting rules shared by all public client operations.
 */
final class KeyFormatter
{
    /**
     * @param array<int, mixed> $options
     */
    public static function prefixed(string $key, array $options): string
    {
        return self::optionString($options[MemcachedConstants::OPT_PREFIX_KEY] ?? '').$key;
    }

    /**
     * @param array<int, mixed> $options
     */
    public static function routing(string $itemKey, array $options): string
    {
        $prefix = self::optionString($options[MemcachedConstants::OPT_PREFIX_KEY] ?? '');
        if (true === ($options[MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY] ?? false) && '' !== $prefix) {
            return $prefix.$itemKey;
        }

        return $itemKey;
    }

    /**
     * @param bool $strictPrintableAscii When {@code true} (default), enforces memcached printable-key rules.
     *                                   When {@code false} (PECL {@code OPT_VERIFY_KEY} off), only length is checked
     *                                   so binary keys can be sent with the meta {@code b} token via {@see encodeMetaKey()}.
     */
    public static function isValid(string $key, bool $strictPrintableAscii = true): bool
    {
        if ('' === $key || \strlen($key) > 250) {
            return false;
        }

        if (!$strictPrintableAscii) {
            return true;
        }

        return 1 === preg_match('/^[\x21-\x7e]+$/', $key);
    }

    /**
     * @return array{0:string,1:string}
     */
    public static function encodeMetaKey(string $key): array
    {
        if (1 === preg_match('/[^\x21-\x7e]/', $key)) {
            return [base64_encode($key), ' b'];
        }

        return [$key, ''];
    }

    private static function optionString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return '';
    }
}
