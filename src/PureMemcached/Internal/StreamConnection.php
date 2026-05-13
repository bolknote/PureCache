<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Buffered TCP connection for memcached text/meta line protocol.
 */
final class StreamConnection
{
    /** @var resource|null */
    private mixed $socket = null;

    private string $readBuffer = '';

    private int $readOffset = 0;

    private string $writeBuffer = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeoutSec,
        private readonly ?int $recvTimeoutUsec,
        private readonly ?int $sendTimeoutUsec,
        private readonly ?string $persistentId = null,
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isConnected(): bool
    {
        return \is_resource($this->socket);
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $target = $this->host;
        if ('/' === $target[0] || ('.' === $target[0] && str_contains($target, '/'))) {
            $uri = 'unix://'.$target;
        } else {
            $uri = 'tcp://'.$target.':'.$this->port;
            if (null !== $this->persistentId && '' !== $this->persistentId) {
                $uri .= '/'.rawurlencode($this->persistentId);
            }
        }

        $flags = \STREAM_CLIENT_CONNECT;
        if (null !== $this->persistentId && '' !== $this->persistentId) {
            $flags |= \STREAM_CLIENT_PERSISTENT;
        }

        $ctx = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            $this->connectTimeoutSec,
            $flags,
            $ctx,
        );
        if (!\is_resource($socket)) {
            $err = error_get_last();
            $msg = $err['message'] ?? $errstr;
            $connectErrno = \is_int($errno) ? $errno : 0;
            throw new ConnectionException(\sprintf('Connect failed to %s:%d: %s (%s)', $this->host, $this->port, $msg, $connectErrno), $connectErrno);
        }

        stream_set_blocking($socket, true);
        if (null !== $this->recvTimeoutUsec && $this->recvTimeoutUsec > 0) {
            stream_set_timeout($socket, intdiv($this->recvTimeoutUsec, 1_000_000), $this->recvTimeoutUsec % 1_000_000);
        }

        if (null !== $this->sendTimeoutUsec && $this->sendTimeoutUsec > 0) {
            // PHP has no portable per-send timeout; recv timeout covers reads after write.
        }

        $this->socket = $socket;
        $this->readBuffer = '';
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->readBuffer = '';
        $this->readOffset = 0;
        $this->writeBuffer = '';
    }

    public function bufferWrite(string $data): void
    {
        $this->writeBuffer .= $data;
    }

    public function write(string $data): void
    {
        $this->connect();
        $this->flushWrite($data);
    }

    public function flushWrite(?string $append = null): void
    {
        if (null !== $append) {
            $this->writeBuffer .= $append;
        }

        if ('' === $this->writeBuffer) {
            return;
        }

        $this->connect();
        $buf = $this->writeBuffer;
        $off = 0;
        $len = \strlen($buf);
        $socket = $this->socket;
        if (!\is_resource($socket)) {
            $this->close();
            throw new ConnectionException('Write failure to memcached: socket is not connected');
        }

        while ($off < $len) {
            if (null !== $this->sendTimeoutUsec && $this->sendTimeoutUsec > 0) {
                $read = null;
                $write = [$socket];
                $except = null;
                $ready = @stream_select(
                    $read,
                    $write,
                    $except,
                    intdiv($this->sendTimeoutUsec, 1_000_000),
                    $this->sendTimeoutUsec % 1_000_000,
                );
                if (false === $ready || 0 === $ready) {
                    $this->writeBuffer = substr($buf, $off);
                    $this->close();
                    throw new ConnectionException('Write timeout to memcached');
                }
            }

            $n = @fwrite($socket, substr($buf, $off));
            if (false === $n || 0 === $n) {
                $this->writeBuffer = substr($buf, $off);
                $err = error_get_last();
                $this->close();
                throw new ConnectionException('Write failure to memcached: '.($err['message'] ?? 'unknown'));
            }

            $off += $n;
        }

        $this->writeBuffer = '';
    }

    private function compactBuffer(): void
    {
        if ($this->readOffset > 16384) {
            $this->readBuffer = substr($this->readBuffer, $this->readOffset);
            $this->readOffset = 0;
        }
    }

    public function readLine(): string
    {
        $this->flushWrite();
        $this->connect();
        while (true) {
            $pos = strpos($this->readBuffer, "\r\n", $this->readOffset);
            if (false !== $pos) {
                $line = substr($this->readBuffer, $this->readOffset, $pos - $this->readOffset);
                $this->readOffset = $pos + 2;
                $this->compactBuffer();

                return $line;
            }

            $socket = $this->socketResource();
            $chunk = fread($socket, 8192);
            if (false === $chunk || '' === $chunk) {
                $meta = stream_get_meta_data($socket);
                $this->close();
                if ($meta['timed_out']) {
                    throw new ConnectionException('Read timeout');
                }

                $err = error_get_last();
                throw new ConnectionException('Connection closed while reading line: '.($err['message'] ?? 'unknown'));
            }

            $this->readBuffer .= $chunk;
        }
    }

    public function readExact(int $length): string
    {
        $this->flushWrite();
        $out = '';
        while (\strlen($out) < $length) {
            $need = $length - \strlen($out);
            $avail = \strlen($this->readBuffer) - $this->readOffset;
            if ($avail > 0) {
                $take = min($need, $avail);
                $out .= substr($this->readBuffer, $this->readOffset, $take);
                $this->readOffset += $take;
                $this->compactBuffer();
                continue;
            }

            $this->connect();
            $socket = $this->socketResource();
            $chunk = fread($socket, max(8192, $need));
            if (false === $chunk || '' === $chunk) {
                $meta = stream_get_meta_data($socket);
                $this->close();
                if ($meta['timed_out']) {
                    throw new ConnectionException('Read timeout');
                }

                $err = error_get_last();
                throw new ConnectionException('Connection closed while reading body: '.($err['message'] ?? 'unknown'));
            }

            $out .= substr($chunk, 0, $need);
            if (\strlen($chunk) > $need) {
                $this->readBuffer = substr($chunk, $need);
                $this->readOffset = 0;
            }
        }

        return $out;
    }

    /**
     * @return resource
     */
    private function socketResource()
    {
        if (!\is_resource($this->socket)) {
            throw new ConnectionException('Socket is not connected');
        }

        return $this->socket;
    }

    public function consumeCrLfAfterBody(): void
    {
        $crlf = $this->readExact(2);
        if ("\r\n" !== $crlf) {
            throw new \RuntimeException('Invalid chunk terminator');
        }
    }
}
