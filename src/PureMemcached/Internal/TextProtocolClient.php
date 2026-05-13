<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Classic memcached text commands used only for operations without a meta equivalent.
 */
final class TextProtocolClient
{
    /**
     * @return array<string, int|float|string>|false
     */
    public static function stats(StreamConnection $c, ?string $type = null): array|false
    {
        $cmd = null !== $type && '' !== $type ? "stats {$type}\r\n" : "stats\r\n";
        $c->write($cmd);
        $out = [];
        while (true) {
            $line = $c->readLine();
            if ('END' === $line) {
                break;
            }

            if (str_starts_with($line, 'STAT ')) {
                $parts = explode(' ', $line, 3);
                $name = $parts[1] ?? '';
                $value = $parts[2] ?? '';
                $out[$name] = self::parseStatsValue($value);
            } elseif ('ERROR' === $line || str_starts_with($line, 'CLIENT_ERROR') || str_starts_with($line, 'SERVER_ERROR')) {
                return false;
            }
        }

        return $out;
    }

    private static function parseStatsValue(string $value): int|float|string
    {
        if (1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (1 === preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    public static function version(StreamConnection $c): string|false
    {
        $c->write("version\r\n");
        $line = $c->readLine();
        if (1 === preg_match('/^VERSION (.+)$/', $line, $m)) {
            return $m[1];
        }

        return false;
    }

    public static function flushAll(StreamConnection $c, int $delay = 0): bool
    {
        $cmd = $delay > 0 ? "flush_all {$delay}\r\n" : "flush_all\r\n";
        $c->write($cmd);
        $line = $c->readLine();

        return 'OK' === $line;
    }

    /**
     * @return list<string>|false
     */
    public static function getAllKeys(StreamConnection $c): array|false
    {
        $c->write("stats items\r\n");
        $slabs = [];
        while (true) {
            $line = $c->readLine();
            if ('END' === $line) {
                break;
            }

            if (1 === preg_match('/^STAT items:(\d+):number/', $line, $m)) {
                $slabs[(int) $m[1]] = true;
            } elseif ('ERROR' === $line || str_starts_with($line, 'CLIENT_ERROR') || str_starts_with($line, 'SERVER_ERROR')) {
                return false;
            }
        }

        $keys = [];
        foreach (array_keys($slabs) as $sid) {
            $c->write("stats cachedump {$sid} 0\r\n");
            while (true) {
                $line = $c->readLine();
                if ('END' === $line) {
                    break;
                }

                if (str_starts_with($line, 'ITEM ')) {
                    if (1 === preg_match('/^ITEM (\S+) /', $line, $m)) {
                        $keys[] = $m[1];
                    }
                } elseif ('ERROR' === $line || str_starts_with($line, 'CLIENT_ERROR') || str_starts_with($line, 'SERVER_ERROR')) {
                    return false;
                }
            }
        }

        return $keys;
    }
}
