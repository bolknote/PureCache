<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Classic memcached text commands used only for operations without a meta equivalent.
 */
final class TextProtocolClient
{
    /**
     * Metadump streams reliably terminate with END from this release onward (see memcached
     * release notes around 1.5.6). Older releases used LF-only item lines without a
     * dependable terminator, so we only use metadump when the server reports >= this.
     */
    private const string METADUMP_MIN_VERSION = '1.5.6';

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
     * Lists keys visible to the server using {@code lru_crawler metadump all} when the
     * reported memcached version is >= {@see METADUMP_MIN_VERSION}, otherwise (or on
     * metadump refusal) falls back to {@code stats items} / {@code stats cachedump}.
     *
     * @return list<string>|false
     */
    public static function getAllKeys(StreamConnection $c): array|false
    {
        $verRaw = self::version($c);
        $verNorm = false !== $verRaw ? self::normalizeMemcachedVersion($verRaw) : null;
        $tryMetadump = null === $verNorm || version_compare($verNorm, self::METADUMP_MIN_VERSION, '>=');

        if ($tryMetadump) {
            $meta = self::getAllKeysViaMetadump($c);
            if (null !== $meta) {
                return $meta;
            }
        }

        return self::getAllKeysViaCachedump($c);
    }

    private static function normalizeMemcachedVersion(string $raw): ?string
    {
        $t = trim($raw);
        if (1 === preg_match('/^(\d+\.\d+\.\d+)/', $t, $m)) {
            return $m[1];
        }

        if (1 === preg_match('/^(\d+\.\d+)\b/', $t, $m)) {
            return $m[1].'.0';
        }

        return null;
    }

    /**
     * @return list<string>|false|null array keys on success, null to fall back to cachedump, false on fatal protocol/read errors
     */
    private static function getAllKeysViaMetadump(StreamConnection $c): array|false|null
    {
        $c->write("lru_crawler metadump all\r\n");
        $line = $c->readLineFlexible();
        while ('OK' === $line) {
            $line = $c->readLineFlexible();
        }

        if (str_starts_with($line, 'NOTSTARTED')) {
            return [];
        }

        if (self::metadumpFirstLineShouldFallback($line)) {
            return null;
        }

        if ('END' === $line) {
            return [];
        }

        $keys = [];
        while (true) {
            $k = self::extractMetadumpKey($line);
            if (null !== $k) {
                $keys[] = $k;
            } elseif ('' !== trim($line)) {
                return false;
            }

            $line = $c->readLineFlexible();
            if ('END' === $line) {
                return $keys;
            }

            if (self::metadumpFirstLineShouldFallback($line)) {
                return false;
            }
        }
    }

    private static function metadumpFirstLineShouldFallback(string $line): bool
    {
        return str_starts_with($line, 'BUSY')
            || str_starts_with($line, 'BADCLASS')
            || str_starts_with($line, 'CLIENT_ERROR')
            || str_starts_with($line, 'SERVER_ERROR')
            || ('' !== $line && str_starts_with($line, 'ERROR'));
    }

    private static function extractMetadumpKey(string $line): ?string
    {
        foreach (explode(' ', $line) as $tok) {
            if ('' === $tok) {
                continue;
            }

            if (str_starts_with($tok, 'key=')) {
                return rawurldecode(substr($tok, 4));
            }
        }

        return null;
    }

    /**
     * @return list<string>|false
     */
    private static function getAllKeysViaCachedump(StreamConnection $c): array|false
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
