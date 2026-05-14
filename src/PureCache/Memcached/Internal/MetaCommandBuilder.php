<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

use PureCache\Internal\KeyFormatter;

/**
 * Builds memcached <a href="https://github.com/memcached/memcached/blob/master/doc/protocol.txt">meta protocol</a>
 * wire commands (`mg`, `ms`, `md`, `ma`).
 *
 * Each helper returns the full command line (including CRLF and, for `ms`, the value
 * payload + trailing CRLF) so callers can pass it straight to {@see StreamConnection::write()}.
 * Key encoding (binary-safe `b` flag) is handled internally via {@see KeyFormatter::encodeMetaKey()}.
 */
final class MetaCommandBuilder
{
    private function __construct()
    {
    }

    /**
     * Read a value with the {@code mg <key> v f t c} request used by {@code get}/{@code getMulti}.
     */
    public static function metaGetValue(string $prefixedKey): string
    {
        [$encodedKey, $bFlag] = KeyFormatter::encodeMetaKey($prefixedKey);

        return 'mg '.$encodedKey.' v f t c'.$bFlag."\r\n";
    }

    /**
     * Touch a key without retrieving its value via {@code mg <key> T<ttl>}.
     */
    public static function metaGetTouch(string $prefixedKey, string $ttlToken): string
    {
        [$encodedKey, $bFlag] = KeyFormatter::encodeMetaKey($prefixedKey);

        return 'mg '.$encodedKey.' T'.$ttlToken.$bFlag."\r\n";
    }

    /**
     * Store a value with arbitrary mode and flag set (`S`/`E`/`R`/`A`/`P`, optional `q`, optional `C<cas>`).
     *
     * @param list<string> $extraFlagTokens already-formatted flag tokens such as {@code 'q'}, {@code 'C12345'}
     */
    public static function metaStore(string $prefixedKey, string $payload, int $userFlags, string $ttlToken, string $mode, array $extraFlagTokens = []): string
    {
        [$encodedKey, $bFlag] = KeyFormatter::encodeMetaKey($prefixedKey);
        $flagParts = ['T'.$ttlToken, 'F'.$userFlags, 'M'.$mode];
        foreach ($extraFlagTokens as $token) {
            $flagParts[] = $token;
        }

        if ('' !== $bFlag) {
            $flagParts[] = trim($bFlag);
        }

        $length = \strlen($payload);

        return 'ms '.$encodedKey.' '.$length.' '.implode(' ', $flagParts)."\r\n".$payload."\r\n";
    }

    /**
     * Delete a key via {@code md}. Pass {@code true} for {@code $noreply} to append the {@code q} flag.
     */
    public static function metaDelete(string $prefixedKey, bool $noreply): string
    {
        [$encodedKey, $bFlag] = KeyFormatter::encodeMetaKey($prefixedKey);
        $flags = '' === $bFlag ? [] : [trim($bFlag)];
        if ($noreply) {
            $flags[] = 'q';
        }

        $suffix = [] === $flags ? '' : ' '.implode(' ', $flags);

        return 'md '.$encodedKey.$suffix."\r\n";
    }

    /**
     * Arithmetic via {@code ma}: {@code D<offset>}, mode {@code MI}/{@code MD} and {@code v} to echo the new value.
     *
     * To stay compatible with PECL {@code Memcached::increment()} /
     * {@code Memcached::decrement()} we also forward {@code initialValue} and
     * {@code expiry} via the meta tokens {@code J<v>} (auto-create initial)
     * and {@code N<ttl>} (auto-create TTL). When both are {@code null} the
     * server returns {@code NF} on a missing key — mirroring PECL's
     * "no autovivification" default.
     */
    public static function metaArith(string $prefixedKey, int $offset, bool $decrement, ?int $initialValue = null, ?int $expiry = null): string
    {
        [$encodedKey, $bFlag] = KeyFormatter::encodeMetaKey($prefixedKey);
        $parts = ['D'.$offset, $decrement ? 'MD' : 'MI', 'v'];
        if (null !== $expiry) {
            $parts[] = 'N'.$expiry;
        }

        if (null !== $initialValue) {
            $parts[] = 'J'.$initialValue;
        }

        if ('' !== $bFlag) {
            $parts[] = trim($bFlag);
        }

        return 'ma '.$encodedKey.' '.implode(' ', $parts)."\r\n";
    }
}
