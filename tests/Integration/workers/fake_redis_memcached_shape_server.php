<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PureCache\Redis\RedisItemScripts;

/**
 * Minimal RESP2 peer for {@see PureCache\Redis\RedisClient} unit tests.
 *
 * Supports HGETALL / EVALSHA (item scripts) / DEL / SCRIPT LOAD / INFO / DBSIZE / SCAN
 * with an in-memory hash store ({@code d}, {@code f}, {@code c} fields).
 */
$port = getenv('FAKE_REDIS_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_REDIS_PORT required\n");
    exit(2);
}

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake redis port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

/** @var array<string, array{d:string, f:string, c:string}> */
$store = [];

serveFakeRedisStoreClients($socket, $store);

/**
 * @param resource                                           $socket
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function serveFakeRedisStoreClients($socket, array &$store): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeRedisStoreClients($socket, $store);

        return;
    }

    stream_set_timeout($client, 5);

    while (true) {
        $argv = readRespArgv($client);
        if ([] === $argv) {
            break;
        }

        dispatchFakeRedisCommand($client, $argv, $store);
    }

    fclose($client);
    serveFakeRedisStoreClients($socket, $store);
}

/**
 * @param resource                                           $client
 * @param list<string>                                       $argv
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function dispatchFakeRedisCommand($client, array $argv, array &$store): void
{
    $cmd = strtoupper($argv[0] ?? '');

    if ('PING' === $cmd) {
        writeSimpleReply($client, 'PONG');

        return;
    }

    if ('AUTH' === $cmd) {
        writeSimpleReply($client, 'OK');

        return;
    }

    if ('SELECT' === $cmd) {
        writeSimpleReply($client, 'OK');

        return;
    }

    if ('QUIT' === $cmd) {
        writeSimpleReply($client, 'OK');

        return;
    }

    if ('HGETALL' === $cmd) {
        writeHgetallReply($client, $argv[1] ?? '', $store);

        return;
    }

    if ('EVALSHA' === $cmd) {
        handleEvalSha($client, $argv, $store);

        return;
    }

    if ('SCRIPT' === $cmd && 'LOAD' === strtoupper($argv[1] ?? '')) {
        $script = $argv[2] ?? '';
        writeBulkReply($client, sha1($script));

        return;
    }

    if ('DEL' === $cmd) {
        $deleted = 0;
        for ($i = 1, $n = count($argv); $i < $n; ++$i) {
            if (isset($store[$argv[$i]])) {
                unset($store[$argv[$i]]);
                ++$deleted;
            }
        }

        writeIntReply($client, $deleted);

        return;
    }

    if ('INFO' === $cmd) {
        writeBulkReply($client, "# Fake\r\nredis_version:7.0.0\r\n");

        return;
    }

    if ('OBJECT' === $cmd) {
        writeBulkReply($client, 'embstr');

        return;
    }

    if ('DBSIZE' === $cmd) {
        writeIntReply($client, count($store));

        return;
    }

    if ('SCAN' === $cmd) {
        writeScanReply($client, $argv, $store);

        return;
    }

    if ('FLUSHDB' === $cmd) {
        $store = [];
        writeSimpleReply($client, 'OK');

        return;
    }

    writeErrorReply($client, 'ERR unknown command '.$cmd);
}

/**
 * @param resource                                           $client
 * @param list<string>                                       $argv
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function handleEvalSha($client, array $argv, array &$store): void
{
    $sha = $argv[1] ?? '';
    $numKeys = (int) ($argv[2] ?? '0');
    $keys = [];
    for ($i = 0; $i < $numKeys; ++$i) {
        $keys[] = $argv[3 + $i] ?? '';
    }

    $args = [];
    for ($i = 3 + $numKeys, $n = count($argv); $i < $n; ++$i) {
        $args[] = $argv[$i];
    }

    $redisKey = $keys[0] ?? '';

    if ($sha === sha1(RedisItemScripts::LUA_ADD)) {
        if (isset($store[$redisKey])) {
            writePairReply($client, -2, '');

            return;
        }

        $store[$redisKey] = ['d' => $args[0], 'f' => $args[1], 'c' => '1'];
        writePairReply($client, 1, '1');

        return;
    }

    if ($sha === sha1(RedisItemScripts::LUA_REPLACE)) {
        if (!isset($store[$redisKey])) {
            writePairReply($client, -2, '');

            return;
        }

        $cas = bumpCas($store[$redisKey]['c']);
        $store[$redisKey] = ['d' => $args[0], 'f' => $args[1], 'c' => $cas];
        writePairReply($client, 1, $cas);

        return;
    }

    if ($sha === sha1(RedisItemScripts::LUA_ARITH)) {
        handleArith($client, $redisKey, $args, $store);

        return;
    }

    if ($sha === sha1(RedisItemScripts::LUA_APPEND_PREPEND)) {
        handleAppendPrepend($client, $redisKey, $args, $store);

        return;
    }

    if ($sha === sha1(RedisItemScripts::LUA_TOUCH)) {
        if (!isset($store[$redisKey])) {
            writeIntReply($client, 0);

            return;
        }

        writeIntReply($client, 1);

        return;
    }

    // CAS_SET / default set: 4 args payload, flags, ttl, expectCas.
    if (count($args) >= 4) {
        $payload = $args[0];
        $flags = $args[1];
        $expectCas = $args[3];

        if (!isset($store[$redisKey])) {
            if ('' !== $expectCas) {
                writePairReply($client, -1, '');

                return;
            }

            $store[$redisKey] = ['d' => $payload, 'f' => $flags, 'c' => '1'];
            writePairReply($client, 1, '1');

            return;
        }

        if ('' !== $expectCas && $store[$redisKey]['c'] !== $expectCas) {
            writePairReply($client, 0, '');

            return;
        }

        $cas = bumpCas($store[$redisKey]['c']);
        $store[$redisKey] = ['d' => $payload, 'f' => $flags, 'c' => $cas];
        writePairReply($client, 1, $cas);

        return;
    }

    writePairReply($client, 1, '1');
}

/**
 * @param resource                                           $client
 * @param list<string>                                       $args
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
/**
 * @param resource                                           $client
 * @param list<string>                                       $args
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function handleAppendPrepend($client, string $redisKey, array $args, array &$store): void
{
    if (!isset($store[$redisKey])) {
        writePairReply($client, -2, '');

        return;
    }

    $piece = $args[0] ?? '';
    $mode = $args[1] ?? 'A';
    $flags = (int) ($store[$redisKey]['f'] ?? '0');
    if (0 !== $flags % 16) {
        writePairReply($client, -2, '');

        return;
    }

    if (1 === (int) floor($flags / 16) % 2) {
        writePairReply($client, -2, '');

        return;
    }

    $current = $store[$redisKey]['d'];
    $store[$redisKey]['d'] = 'P' === $mode ? $piece.$current : $current.$piece;
    $cas = bumpCas($store[$redisKey]['c']);
    $store[$redisKey]['c'] = $cas;
    writePairReply($client, 1, $cas);
}

/**
 * @param resource                                           $client
 * @param list<string>                                       $args
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function handleArith($client, string $redisKey, array $args, array &$store): void
{
    $offset = (int) $args[0];
    $decrement = 'D' === $args[1];
    $initial = $args[2];
    $typeFlag = $args[4] ?? '1';

    if (!isset($store[$redisKey])) {
        if ('' === $initial) {
            writeTripleReply($client, -1, '', '');

            return;
        }

        $store[$redisKey] = ['d' => $initial, 'f' => $typeFlag, 'c' => '1'];
        writeTripleReply($client, 1, $initial, '1');

        return;
    }

    $current = (int) $store[$redisKey]['d'];
    $next = $decrement ? max(0, $current - $offset) : $current + $offset;
    $cas = bumpCas($store[$redisKey]['c']);
    $store[$redisKey]['d'] = (string) $next;
    $store[$redisKey]['c'] = $cas;
    writeTripleReply($client, 1, (string) $next, $cas);
}

/**
 * @param resource                                           $client
 * @param list<string>                                       $argv
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function writeScanReply($client, array $argv, array $store): void
{
    $match = '';
    for ($i = 1, $n = count($argv); $i + 1 < $n; ++$i) {
        if ('MATCH' === strtoupper($argv[$i])) {
            $match = $argv[$i + 1];
        }
    }

    $keys = array_keys($store);
    if ('' !== $match) {
        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => fnmatch($match, $key),
        ));
    }

    fwrite($client, "*2\r\n:0\r\n");
    fwrite($client, '*'.count($keys)."\r\n");
    foreach ($keys as $key) {
        writeBulkReply($client, $key);
    }
}

function bumpCas(string $current): string
{
    $n = (int) $current;

    return (string) ($n + 1);
}

/**
 * @param resource                                           $client
 * @param array<string, array{d:string, f:string, c:string}> $store
 */
function writeHgetallReply($client, string $key, array $store): void
{
    if (!isset($store[$key])) {
        fwrite($client, "*0\r\n");

        return;
    }

    $item = $store[$key];
    $parts = ['d', $item['d'], 'f', $item['f'], 'c', $item['c']];
    fwrite($client, '*'.count($parts)."\r\n");
    for ($i = 0, $n = count($parts); $i < $n; ++$i) {
        writeBulkReply($client, $parts[$i]);
    }
}

/**
 * @param resource $client
 */
function writePairReply($client, int $status, string $cas): void
{
    fwrite($client, "*2\r\n");
    writeIntReply($client, $status);
    writeBulkReply($client, $cas);
}

/**
 * @param resource $client
 */
function writeTripleReply($client, int $status, string $value, string $cas): void
{
    fwrite($client, "*3\r\n");
    writeIntReply($client, $status);
    writeBulkReply($client, $value);
    writeBulkReply($client, $cas);
}

/**
 * @param resource $stream
 *
 * @return list<string>
 */
function readRespArgv($stream): array
{
    $line = fgets($stream);
    if (false === $line || !str_starts_with($line, '*')) {
        return [];
    }

    $argc = (int) substr($line, 1);
    if ($argc < 0) {
        return [];
    }

    $argv = [];
    for ($i = 0; $i < $argc; ++$i) {
        $lenLine = fgets($stream);
        if (false === $lenLine || !str_starts_with($lenLine, '$')) {
            return [];
        }

        $len = (int) substr($lenLine, 1);
        if ($len < 0) {
            $argv[] = '';

            continue;
        }

        $payload = stream_get_contents($stream, $len + 2);
        if (false === $payload || strlen($payload) < $len + 2) {
            return [];
        }

        $argv[] = substr($payload, 0, $len);
    }

    return $argv;
}

/**
 * @param resource $client
 */
function writeBulkReply($client, string $value): void
{
    fwrite($client, '$'.strlen($value)."\r\n".$value."\r\n");
}

/**
 * @param resource $client
 */
function writeIntReply($client, int $value): void
{
    fwrite($client, ':'.$value."\r\n");
}

/**
 * @param resource $client
 */
function writeSimpleReply($client, string $value): void
{
    fwrite($client, '+'.$value."\r\n");
}

/**
 * @param resource $client
 */
function writeErrorReply($client, string $message): void
{
    fwrite($client, '-'.$message."\r\n");
}
