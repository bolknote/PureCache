<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Parses libmemcached-style connection strings passed to {@see \Memcached::__construct()} as the third argument.
 *
 * Supports comma- or whitespace-separated tokens, optional {@code --SERVER=} prefix, {@code host:port} and
 * {@code [ipv6]:port}, optional weight as a third segment ({@code host:port:weight} or {@code [ipv6]:port:weight}).
 *
 * Additionally understands URL-style tokens that carry per-server AUTH/database
 * information for Redis backends:
 *
 *   - {@code redis://host[:port][/db]}
 *   - {@code redis://:password@host[:port][/db]}
 *   - {@code redis://user:password@host[:port][/db]}
 *   - {@code rediss://…} — same fields plus {@code tls: true} (TLS via {@code ssl://})
 *
 * Those return the optional {@code user}, {@code password}, {@code database}, and
 * {@code tls} fields which Memcached-only backends safely ignore.
 */
final class ConnectionStringParser
{
    /**
     * @return list<array{host:string,port:int,weight:int,user?:string,password?:string,database?:int,tls?:bool,tls_ca_file?:string}>
     */
    public static function parseServers(string $connectionStr): array
    {
        $connectionStr = trim($connectionStr);
        if ('' === $connectionStr) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $connectionStr, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            return [];
        }

        $out = [];
        foreach ($parts as $raw) {
            $token = trim($raw);
            if ('' === $token) {
                continue;
            }

            if (str_starts_with($token, '--SERVER=')) {
                $token = trim(substr($token, \strlen('--SERVER=')));
            } elseif (str_starts_with($token, '--server=')) {
                $token = trim(substr($token, \strlen('--server=')));
            }

            if ('' === $token) {
                continue;
            }

            $parsed = self::parseOne($token);
            if (null !== $parsed) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * @return array{host:string,port:int,weight:int,user?:string,password?:string,database?:int,tls?:bool,tls_ca_file?:string}|null
     */
    private static function parseOne(string $token): ?array
    {
        if (str_starts_with($token, 'redis://') || str_starts_with($token, 'rediss://')) {
            return self::parseRedisUrl($token);
        }

        if (str_starts_with($token, '[')) {
            if (1 === preg_match('/^\[([^\]]+)]:(\d+):(\d+)$/', $token, $m)) {
                return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => (int) $m[3]];
            }

            if (1 === preg_match('/^\[([^\]]+)]:(\d+)$/', $token, $m)) {
                return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => 0];
            }

            return null;
        }

        if (1 === preg_match('/^([^:]+):(\d+):(\d+)$/', $token, $m)) {
            return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => (int) $m[3]];
        }

        if (1 === preg_match('/^([^:]+):(\d+)$/', $token, $m)) {
            return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => 0];
        }

        return null;
    }

    /**
     * @return array{host:string,port:int,weight:int,user?:string,password?:string,database?:int,tls?:bool,tls_ca_file?:string}|null
     */
    private static function parseRedisUrl(string $token): ?array
    {
        $parts = parse_url($token);
        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            return null;
        }

        $tls = isset($parts['scheme']) && 'rediss' === $parts['scheme'];
        $defaultPort = $tls ? 6380 : 0;

        $entry = [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? $defaultPort,
            'weight' => 0,
        ];

        if ($tls) {
            $entry['tls'] = true;
        }

        if (isset($parts['user']) && '' !== $parts['user']) {
            $entry['user'] = rawurldecode($parts['user']);
        }

        if (isset($parts['pass']) && '' !== $parts['pass']) {
            $entry['password'] = rawurldecode($parts['pass']);
        }

        if (isset($parts['path']) && '' !== $parts['path']) {
            $db = ltrim($parts['path'], '/');
            if (ctype_digit($db)) {
                $entry['database'] = (int) $db;
            }
        }

        if (isset($parts['query']) && '' !== $parts['query']) {
            parse_str($parts['query'], $query);
            $caFile = $query['cafile'] ?? $query['tls_ca_file'] ?? null;
            if (\is_string($caFile) && '' !== $caFile) {
                $entry['tls_ca_file'] = rawurldecode($caFile);
            }
        }

        return $entry;
    }
}
