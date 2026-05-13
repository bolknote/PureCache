<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Parses libmemcached-style connection strings passed to {@see \Memcached::__construct()} as the third argument.
 *
 * Supports comma- or whitespace-separated tokens, optional {@code --SERVER=} prefix, {@code host:port} and
 * {@code [ipv6]:port}, optional weight as a third segment ({@code host:port:weight} or {@code [ipv6]:port:weight}).
 */
final class ConnectionStringParser
{
    /**
     * @return list<array{host:string,port:int,weight:int}>
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
     * @return array{host:string,port:int,weight:int}|null
     */
    private static function parseOne(string $token): ?array
    {
        if (str_starts_with($token, '[')) {
            if (1 === preg_match('/^\[([^\]]+)]:(\d+):(\d+)$/', $token, $m)) {
                return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => (int) $m[3]];
            }

            if (1 === preg_match('/^\[([^\]]+)]:(\d+)$/', $token, $m)) {
                return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => 0];
            }

            return null;
        }

        if (1 === preg_match('/^(.+):(\d+):(\d+)$/', $token, $m)) {
            return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => (int) $m[3]];
        }

        if (1 === preg_match('/^([^:]+):(\d+)$/', $token, $m)) {
            return ['host' => $m[1], 'port' => (int) $m[2], 'weight' => 0];
        }

        return null;
    }
}
