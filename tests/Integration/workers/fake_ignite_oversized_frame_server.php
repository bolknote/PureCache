<?php

declare(strict_types=1);

$port = getenv('FAKE_IGNITE_PORT');
if (false === $port || '' === $port || !ctype_digit($port)) {
    fwrite(\STDERR, "FAKE_IGNITE_PORT required\n");
    exit(2);
}

$declared = getenv('FAKE_IGNITE_FRAME_SIZE');
if (false === $declared || '' === $declared || !ctype_digit($declared)) {
    $declared = '200';
}

$oversizedFrame = (int) $declared;

$socket = stream_socket_server('tcp://127.0.0.1:'.(int) $port);
if (false === $socket) {
    fwrite(\STDERR, "failed to bind fake ignite port\n");
    exit(1);
}

fwrite(\STDOUT, "ready\n");

serveFakeIgniteClients($socket, $oversizedFrame);

/**
 * @param resource $socket
 */
function serveFakeIgniteClients($socket, int $oversizedFrame): void
{
    $client = @stream_socket_accept($socket, 1.0);
    if (!is_resource($client)) {
        serveFakeIgniteClients($socket, $oversizedFrame);

        return;
    }

    stream_set_timeout($client, 5);

    $handshake = readIgniteFrame($client);
    if (null === $handshake) {
        fclose($client);
        serveFakeIgniteClients($socket, $oversizedFrame);

        return;
    }

    // HANDSHAKE_OK (single status byte).
    writeIgniteFrame($client, "\x01");

    $requestCount = 0;
    while (true) {
        $request = readIgniteFrame($client);
        if (null === $request || strlen($request) < 10) {
            break;
        }

        ++$requestCount;
        $requestId = substr($request, 2, 8);

        if ($requestCount >= 2) {
            // Legal frame size but a byte_array header that declares more bytes than allowed.
            $status = pack('V', 0);
            $arrayHeader = "\x0C".pack('V', $oversizedFrame);
            writeIgniteFrame($client, $requestId.$status.$arrayHeader);
            break;
        }

        // Normal OK response for cache create / first opcode (LE int32 status).
        $payload = $requestId.pack('V', 0);
        writeIgniteFrame($client, $payload);
    }

    fclose($client);
    serveFakeIgniteClients($socket, $oversizedFrame);
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

    $unpacked = unpack('V', $header);
    if (!is_array($unpacked) || !isset($unpacked[1]) || !is_int($unpacked[1])) {
        return null;
    }

    $length = $unpacked[1];
    if ($length < 0) {
        return null;
    }

    if (0 === $length) {
        return '';
    }

    $body = stream_get_contents($client, $length);

    return is_string($body) && strlen($body) === $length ? $body : null;
}

/**
 * @param resource $client
 */
function writeIgniteFrame($client, string $body): void
{
    fwrite($client, pack('V', strlen($body)).$body);
}
