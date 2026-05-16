<?php

declare(strict_types=1);

$port = getenv('FAKE_IGNITE_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_IGNITE_PORT required\n");
    exit(2);
}

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake ignite port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

/** @var array<string, string> */
$store = [];

serveFakeIgniteStoreClients($socket, $store);

/**
 * @param resource             $socket
 * @param array<string,string> $store
 */
function serveFakeIgniteStoreClients($socket, array &$store): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeIgniteStoreClients($socket, $store);

        return;
    }

    stream_set_timeout($client, 5);

    $handshake = readIgniteFrame($client);
    if (null === $handshake) {
        fclose($client);
        serveFakeIgniteStoreClients($socket, $store);

        return;
    }

    fwrite($client, pack('V', 1)."\x01");

    while (true) {
        $message = readIgniteFrame($client);
        if (null === $message || strlen($message) < 10) {
            break;
        }

        $op = igniteUnpackInt16($message, 0);
        $requestId = igniteUnpackInt64($message, 2);
        $body = substr($message, 10);

        $payload = handleIgniteOpcode($op, $body, $store);
        writeIgniteResponse($client, $requestId, $payload);
    }

    fclose($client);
    serveFakeIgniteStoreClients($socket, $store);
}

/**
 * @param array<string, string> $store
 */
function handleIgniteOpcode(int $op, string $body, array &$store): string
{
    return match ($op) {
        1052 => '',
        1000 => handleCacheGet($body, $store),
        1001 => handleCachePut($body, $store),
        1002 => handleCachePutIfAbsent($body, $store),
        1003 => handleCacheGetAll($body, $store),
        1009 => handleCacheReplace($body, $store),
        1010 => handleCacheReplaceIfEquals($body, $store),
        1016 => handleCacheRemove($body, $store),
        1013 => handleCacheClear($store),
        1020 => handleCacheGetSize($store),
        2004 => handleSqlFieldsVersion(),
        2000 => handleQueryScan($store),
        0 => '',
        default => '',
    };
}

/**
 * @param array<string, string> $store
 */
function handleCacheGet(string $body, array $store): string
{
    $key = parseCacheKey($body);
    if (null === $key || !isset($store[$key])) {
        return igniteEncodeNullObject();
    }

    return igniteEncodeByteArrayObject($store[$key]);
}

/**
 * @param array<string, string> $store
 */
function handleCachePut(string $body, array &$store): string
{
    [$key, $value] = parseCacheKeyAndValue($body);
    if (null === $key || null === $value) {
        return '';
    }

    $store[$key] = $value;

    return '';
}

/**
 * @param array<string, string> $store
 */
function handleCachePutIfAbsent(string $body, array &$store): string
{
    [$key, $value] = parseCacheKeyAndValue($body);
    if (null === $key || null === $value) {
        return igniteEncodeBool(false);
    }

    if (isset($store[$key])) {
        return igniteEncodeBool(false);
    }

    $store[$key] = $value;

    return igniteEncodeBool(true);
}

/**
 * @param array<string, string> $store
 */
function handleCacheReplace(string $body, array &$store): string
{
    [$key, $value] = parseCacheKeyAndValue($body);
    if (null === $key || null === $value || !isset($store[$key])) {
        return igniteEncodeBool(false);
    }

    $store[$key] = $value;

    return igniteEncodeBool(true);
}

/**
 * @param array<string, string> $store
 */
function handleCacheReplaceIfEquals(string $body, array &$store): string
{
    $key = parseCacheKeyOnly($body);
    if (null === $key) {
        return igniteEncodeBool(false);
    }

    $offset = cacheKeyBodyLength($body);
    if (null === $offset) {
        return igniteEncodeBool(false);
    }

    [, $offset] = igniteReadStringObject($body, $offset);
    [$expected, $offset] = igniteReadByteArrayObject($body, $offset);
    [$newValue] = igniteReadByteArrayObject($body, $offset);
    if (null === $newValue) {
        return igniteEncodeBool(false);
    }

    if (!isset($store[$key]) || $store[$key] !== $expected) {
        return igniteEncodeBool(false);
    }

    $store[$key] = $newValue;

    return igniteEncodeBool(true);
}

/**
 * @param array<string, string> $store
 */
function handleCacheRemove(string $body, array &$store): string
{
    $key = parseCacheKey($body);
    if (null === $key || !isset($store[$key])) {
        return igniteEncodeBool(false);
    }

    unset($store[$key]);

    return igniteEncodeBool(true);
}

/**
 * @param array<string, string> $store
 */
function handleCacheClear(array &$store): string
{
    foreach (array_keys($store) as $key) {
        unset($store[$key]);
    }

    return '';
}

/**
 * @param array<string, string> $store
 */
function handleCacheGetSize(array $store): string
{
    return pack('P', count($store));
}

/**
 * @param array<string, string> $store
 */
function handleCacheGetAll(string $body, array $store): string
{
    if (strlen($body) < 9) {
        return pack('V', 0);
    }

    $offset = 5;
    $keyCount = igniteUnpackInt32($body, $offset);
    $offset += 4;
    $entries = '';
    $count = 0;
    for ($i = 0; $i < $keyCount; ++$i) {
        [$key, $offset] = igniteReadStringObject($body, $offset);
        if (!isset($store[$key])) {
            continue;
        }

        ++$count;
        $entries .= igniteEncodeStringObject($key).igniteEncodeByteArrayObject($store[$key]);
    }

    return pack('V', $count).$entries;
}

function handleSqlFieldsVersion(): string
{
    return pack('P', 0)
        .pack('V', 1)
        .pack('V', 1)
        .igniteEncodeStringObject('2.17.0-fake')
        .igniteEncodeBool(false);
}

/**
 * @param array<string, string> $store
 */
function handleQueryScan(array $store): string
{
    $rows = '';
    foreach (array_keys($store) as $key) {
        $rows .= igniteEncodeStringObject($key).igniteEncodeByteArrayObject('');
    }

    return pack('P', 0).pack('V', count($store)).$rows.igniteEncodeBool(false);
}

function parseCacheKey(string $body): ?string
{
    return parseCacheKeyOnly($body);
}

function parseCacheKeyOnly(string $body): ?string
{
    $offset = cacheKeyBodyLength($body);
    if (null === $offset) {
        return null;
    }

    [$key] = igniteReadStringObject($body, $offset);

    return '' === $key ? null : $key;
}

/**
 * @return array{0:?string,1:?string,2:int}
 */
function parseCacheKeyAndValue(string $body): array
{
    $offset = cacheKeyBodyLength($body);
    if (null === $offset) {
        return [null, null, 0];
    }

    [$key, $offset] = igniteReadStringObject($body, $offset);
    [$value, $next] = igniteReadByteArrayObject($body, $offset);

    return ['' === $key ? null : $key, $value, $next];
}

function cacheKeyBodyLength(string $body): ?int
{
    if (strlen($body) < 5) {
        return null;
    }

    return 5;
}

/**
 * @return array{0:string,1:int}
 */
function igniteReadStringObject(string $body, int $offset): array
{
    if (strlen($body) < $offset + 1) {
        return ['', $offset];
    }

    $type = ord($body[$offset]);
    if (101 === $type) {
        return ['', $offset + 1];
    }

    if (9 !== $type || strlen($body) < $offset + 5) {
        return ['', $offset + 1];
    }

    $length = igniteUnpackInt32($body, $offset + 1);

    return [substr($body, $offset + 5, $length), $offset + 5 + $length];
}

/**
 * @return array{0:?string,1:int}
 */
function igniteReadByteArrayObject(string $body, int $offset): array
{
    if (strlen($body) < $offset + 1) {
        return [null, $offset];
    }

    $type = ord($body[$offset]);
    if (101 === $type) {
        return [null, $offset + 1];
    }

    if (12 !== $type || strlen($body) < $offset + 5) {
        return [null, $offset + 1];
    }

    $length = igniteUnpackInt32($body, $offset + 1);

    return [substr($body, $offset + 5, $length), $offset + 5 + $length];
}

function igniteEncodeStringObject(string $value): string
{
    return "\x09".pack('V', strlen($value)).$value;
}

function igniteEncodeByteArrayObject(string $bytes): string
{
    return "\x0C".pack('V', strlen($bytes)).$bytes;
}

function igniteEncodeNullObject(): string
{
    return "\x65";
}

function igniteEncodeBool(bool $value): string
{
    return $value ? "\x01" : "\x00";
}

/**
 * @param resource $client
 */
function writeIgniteResponse($client, int $requestId, string $payload): void
{
    $body = pack('P', $requestId).pack('V', 0).$payload;
    fwrite($client, pack('V', strlen($body)).$body);
}

/**
 * @param resource $client
 */
function readIgniteFrame($client): ?string
{
    $header = stream_get_contents($client, 4);
    if (false === $header || 4 !== strlen($header)) {
        return null;
    }

    $length = igniteUnpackInt32($header, 0);
    if ($length < 0) {
        return null;
    }

    if (0 === $length) {
        return '';
    }

    $body = stream_get_contents($client, $length);

    return is_string($body) && strlen($body) === $length ? $body : null;
}

function igniteUnpackInt16(string $bytes, int $offset): int
{
    $u = unpack('v', substr($bytes, $offset, 2));
    if (!is_array($u) || !array_key_exists(1, $u) || !is_int($u[1])) {
        return 0;
    }

    return $u[1];
}

function igniteUnpackInt32(string $bytes, int $offset): int
{
    $u = unpack('V', substr($bytes, $offset, 4));
    if (!is_array($u) || !array_key_exists(1, $u) || !is_int($u[1])) {
        return 0;
    }

    return $u[1];
}

function igniteUnpackInt64(string $bytes, int $offset): int
{
    $u = unpack('P', substr($bytes, $offset, 8));
    if (!is_array($u) || !array_key_exists(1, $u) || !is_int($u[1])) {
        return 0;
    }

    return $u[1];
}
