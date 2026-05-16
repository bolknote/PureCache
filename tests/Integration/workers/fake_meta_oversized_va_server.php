<?php

declare(strict_types=1);

$port = getenv('FAKE_META_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_META_PORT required\n");
    exit(2);
}

$declared = getenv('FAKE_META_VA_SIZE');
if (false === $declared || '' === $declared || !ctype_digit($declared)) {
    $declared = '200';
}

$bodySize = (int) $declared;
$body = str_repeat('z', $bodySize);

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake meta port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

serveFakeMetaClients($socket, $bodySize, $body);

/**
 * @param resource $socket
 */
function serveFakeMetaClients($socket, int $bodySize, string $body): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeMetaClients($socket, $bodySize, $body);

        return;
    }

    stream_set_timeout($client, 5);
    while (true) {
        $line = fgets($client);
        if (false === $line) {
            break;
        }

        if (str_starts_with($line, 'mg ') || str_starts_with($line, 'gets ')) {
            fwrite($client, 'VA '.$bodySize.' f0'."\r\n".$body."\r\n");
            fwrite($client, 'EN'."\r\n");
            break;
        }
    }

    fclose($client);
    serveFakeMetaClients($socket, $bodySize, $body);
}
