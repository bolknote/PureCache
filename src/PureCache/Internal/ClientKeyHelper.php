<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Key coercion and PECL {@code GET_EXTENDED} / delayed-fetch row shaping.
 */
final readonly class ClientKeyHelper
{
    public function __construct(private ClientCoordinatorEnv $env)
    {
    }

    /**
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    public function strings(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->toString($key);
        }

        return $out;
    }

    /**
     * @return string the key in canonical string form, or {@code ''} (which
     *                {@see KeyFormatter::isValid()} will reject as
     *                {@code RES_BAD_KEY_PROVIDED}) if {@code $key} is not a
     *                memcached-compatible scalar or {@see \Stringable}
     */
    public function toString(mixed $key): string
    {
        if (\is_string($key)) {
            return $key;
        }

        if (\is_int($key) || \is_float($key) || null === $key || \is_bool($key)) {
            return (string) $key;
        }

        if ($key instanceof \Stringable) {
            return (string) $key;
        }

        $this->env->setResult(
            MemcachedConstants::RES_BAD_KEY_PROVIDED,
            'key must be a string, got '.get_debug_type($key),
        );

        return '';
    }

    public static function casValue(?string $cas): int|string
    {
        if (null === $cas || '' === $cas) {
            return 0;
        }

        if ((string) (int) $cas !== $cas) {
            return $cas;
        }

        return (int) $cas;
    }

    public static function valueForGetFlags(CacheEntry $entry, int $getFlags): mixed
    {
        if (($getFlags & MemcachedConstants::GET_EXTENDED) !== 0) {
            return [
                'value' => $entry->value,
                'cas' => $entry->cas,
                'flags' => $entry->userFlags,
            ];
        }

        return $entry->value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function delayedEntry(string $key, CacheEntry $entry, bool $withCas): array
    {
        $row = ['key' => $key, 'value' => $entry->value];
        if ($withCas) {
            $row['cas'] = $entry->cas;
            $row['flags'] = $entry->userFlags;
        }

        return $row;
    }
}
