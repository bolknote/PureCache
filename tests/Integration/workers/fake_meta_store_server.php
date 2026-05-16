<?php

declare(strict_types=1);

$port = getenv('FAKE_META_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_META_PORT required\n");
    exit(2);
}

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake meta port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

/** @var array<string, array{body:string, flags:string}> */
$store = [];

serveFakeMetaStoreClients($socket, $store);

/**
 * @param resource                                        $socket
 * @param array<string, array{body:string, flags:string}> $store
 */
function serveFakeMetaStoreClients($socket, array &$store): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeMetaStoreClients($socket, $store);

        return;
    }

    stream_set_timeout($client, 5);
    while (true) {
        $line = fgets($client);
        if (false === $line) {
            break;
        }

        $trimmed = trim($line);
        if ('' === $trimmed) {
            continue;
        }

        if (str_starts_with($trimmed, 'ms ')) {
            handleMetaStore($client, $trimmed, $store);
            continue;
        }

        if (str_starts_with($trimmed, 'mg ')) {
            handleMetaGet($client, $trimmed, $store);
            continue;
        }

        if (str_starts_with($trimmed, 'md ')) {
            handleMetaDelete($client, $trimmed, $store);
            continue;
        }

        if (str_starts_with($trimmed, 'ma ')) {
            handleMetaArith($client, $trimmed, $store);
            continue;
        }

        if (str_starts_with($trimmed, 'stats cachedump')) {
            handleStatsCachedump($client, $store);
            continue;
        }

        if (str_starts_with($trimmed, 'stats')) {
            handleTextStats($client, $trimmed);
            continue;
        }

        if ('version' === $trimmed) {
            $ver = getenv('FAKE_META_VERSION');
            if (false === $ver || '' === $ver) {
                $ver = '1.6.22-fake';
            }

            fwrite($client, 'VERSION '.$ver."\r\n");
            continue;
        }

        if (str_starts_with($trimmed, 'flush_all')) {
            $store = [];
            fwrite($client, "OK\r\n");
            continue;
        }

        if (str_starts_with($trimmed, 'lru_crawler metadump all')) {
            handleMetadump($client, $store);
            continue;
        }

        fwrite($client, "CLIENT_ERROR unknown command\r\n");
    }

    fclose($client);
    serveFakeMetaStoreClients($socket, $store);
}

/**
 * @param list<string> $parts
 */
function metaStoreMode(array $parts): string
{
    foreach ($parts as $token) {
        if (str_starts_with($token, 'M') && strlen($token) >= 2) {
            return substr($token, 1);
        }
    }

    return 'S';
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleMetaStore($client, string $line, array &$store): void
{
    $parts = preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY);
    if (false === $parts || count($parts) < 3) {
        fwrite($client, "CLIENT_ERROR bad ms\r\n");

        return;
    }

    $key = $parts[1];
    $length = (int) $parts[2];
    $flags = '0';
    foreach ($parts as $token) {
        if (str_starts_with($token, 'F')) {
            $flags = substr($token, 1);
        }
    }

    $body = '';
    if ($length > 0) {
        $payload = stream_get_contents($client, $length + 2);
        if (false === $payload || strlen($payload) < $length) {
            return;
        }

        $body = substr($payload, 0, $length);
    }

    $mode = metaStoreMode($parts);
    $exists = isset($store[$key]);

    if ('E' === $mode) {
        if ($exists) {
            fwrite($client, "NS\r\n");

            return;
        }
    } elseif ('R' === $mode) {
        if (!$exists) {
            fwrite($client, "NF\r\n");

            return;
        }
    } elseif ('A' === $mode) {
        if (!$exists) {
            fwrite($client, "NF\r\n");

            return;
        }

        $body = $store[$key]['body'].$body;
        $flags = $store[$key]['flags'];
    } elseif ('P' === $mode) {
        if (!$exists) {
            fwrite($client, "NF\r\n");

            return;
        }

        $body .= $store[$key]['body'];
        $flags = $store[$key]['flags'];
    }

    $store[$key] = ['body' => $body, 'flags' => $flags];
    fwrite($client, "HD\r\n");
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleMetaGet($client, string $line, array &$store): void
{
    $parts = preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY);
    if (false === $parts || count($parts) < 2) {
        fwrite($client, "CLIENT_ERROR bad mg\r\n");

        return;
    }

    $key = $parts[1];
    $wantsValue = in_array('v', $parts, true);
    if (!$wantsValue) {
        if (!isset($store[$key])) {
            fwrite($client, "EN\r\n");

            return;
        }

        fwrite($client, "HD\r\n");

        return;
    }

    if (!isset($store[$key])) {
        fwrite($client, "EN\r\n");

        return;
    }

    $item = $store[$key];
    $len = strlen($item['body']);
    fwrite($client, 'VA '.$len.' f'.$item['flags']." c1\r\n");
    if ($len > 0) {
        fwrite($client, $item['body']."\r\n");
    }
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleMetaDelete($client, string $line, array &$store): void
{
    $parts = preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY);
    if (false === $parts || count($parts) < 2) {
        fwrite($client, "CLIENT_ERROR bad md\r\n");

        return;
    }

    $key = $parts[1];
    if (!isset($store[$key])) {
        fwrite($client, "NF\r\n");

        return;
    }

    unset($store[$key]);
    fwrite($client, "HD\r\n");
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleMetaArith($client, string $line, array &$store): void
{
    $parts = preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY);
    if (false === $parts || count($parts) < 2) {
        fwrite($client, "CLIENT_ERROR bad ma\r\n");

        return;
    }

    $key = $parts[1];
    $delta = 1;
    $decrement = false;
    $initial = null;
    foreach ($parts as $token) {
        if (str_starts_with($token, 'D')) {
            $delta = (int) substr($token, 1);
        } elseif ('MD' === $token) {
            $decrement = true;
        } elseif (str_starts_with($token, 'J')) {
            $initial = (int) substr($token, 1);
        }
    }

    if (!isset($store[$key])) {
        if (null === $initial) {
            fwrite($client, "NF\r\n");

            return;
        }

        $store[$key] = ['body' => (string) $initial, 'flags' => '0'];
        fwrite($client, $initial."\r\n");

        return;
    }

    $current = (int) $store[$key]['body'];
    $next = $decrement ? $current - $delta : $current + $delta;
    $store[$key] = ['body' => (string) $next, 'flags' => '0'];

    fwrite($client, $next."\r\n");
}

/**
 * @param resource $client
 */
function handleTextStats($client, string $line): void
{
    $parts = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY);
    if (false === $parts) {
        $parts = [];
    }

    $type = $parts[1] ?? '';
    if ('' === $type) {
        fwrite($client, "STAT pid 1\r\n");
        fwrite($client, "STAT curr_items 1\r\n");
        fwrite($client, "END\r\n");

        return;
    }

    if ('items' === $type) {
        fwrite($client, "STAT items:1:number 1\r\n");
        fwrite($client, "END\r\n");

        return;
    }

    if ('slabs' === $type) {
        fwrite($client, "STAT slabs:1:chunk_size 96\r\n");
        fwrite($client, "END\r\n");

        return;
    }

    fwrite($client, "END\r\n");
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleStatsCachedump($client, array $store): void
{
    foreach (array_keys($store) as $key) {
        fwrite($client, 'ITEM '.$key." [0 b; 0 s]\r\n");
    }

    fwrite($client, "END\r\n");
}

/**
 * @param resource                                        $client
 * @param array<string, array{body:string, flags:string}> $store
 */
function handleMetadump($client, array $store): void
{
    fwrite($client, "OK\r\n");
    foreach (array_keys($store) as $key) {
        fwrite($client, 'ITEM key='.rawurlencode($key)." [0 b; 0 s]\r\n");
    }

    fwrite($client, "END\r\n");
}
