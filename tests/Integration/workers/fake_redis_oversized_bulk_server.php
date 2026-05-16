<?php

declare(strict_types=1);

$port = getenv('FAKE_REDIS_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_REDIS_PORT required\n");
    exit(2);
}

$declared = getenv('FAKE_REDIS_BULK_SIZE');
if (false === $declared || '' === $declared || !ctype_digit($declared)) {
    $declared = '200';
}

$bulkSize = (int) $declared;

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake redis port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

serveFakeRedisClients($socket, $bulkSize);

/**
 * @param resource $socket
 */
function serveFakeRedisClients($socket, int $bulkSize): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeRedisClients($socket, $bulkSize);

        return;
    }

    stream_set_timeout($client, 5);
    while (true) {
        $line = fgets($client);
        if (false === $line) {
            break;
        }

        if (!str_starts_with($line, '*')) {
            continue;
        }

        $argc = (int) substr($line, 1);
        for ($i = 0; $i < $argc; ++$i) {
            $bulkLenLine = fgets($client);
            if (false === $bulkLenLine) {
                break 2;
            }

            $argLen = (int) substr($bulkLenLine, 1);
            if ($argLen > 0) {
                $payload = stream_get_contents($client, $argLen + 2);
                if (false === $payload) {
                    break 2;
                }
            }
        }

        // HGETALL-shaped reply: d, f, c fields with an oversized `d` bulk body.
        $flags = '0';
        $cas = '1';
        $dField = 'd';
        $fField = 'f';
        $cField = 'c';
        $body = str_repeat('z', $bulkSize);

        $parts = [
            '*6',
            '$'.strlen($dField),
            $dField,
            '$'.$bulkSize,
            $body,
            '$'.strlen($fField),
            $fField,
            '$'.strlen($flags),
            $flags,
            '$'.strlen($cField),
            $cField,
            '$'.strlen($cas),
            $cas,
        ];

        fwrite($client, implode("\r\n", $parts)."\r\n");
        break;
    }

    fclose($client);
    serveFakeRedisClients($socket, $bulkSize);
}
