<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

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
        private readonly bool $tcpNoDelay = false,
        private readonly bool $tcpKeepAlive = false,
        private readonly int $socketSendSize = 0,
        private readonly int $socketRecvSize = 0,
        private readonly bool $tcpCork = false,
        private readonly int $pollTimeoutMs = 1000,
        private readonly int $ioBytesWatermark = 0,
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

        $target = $this->host;
        $isTcp = true;
        if ('/' === $target[0] || ('.' === $target[0] && str_contains($target, '/'))) {
            $uri = 'unix://'.$target;
            $isTcp = false;
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

        $this->socket = $this->openSocket(
            $uri,
            $flags,
            \sprintf('Connect failed to %s:%d', $this->host, $this->port),
            $isTcp,
        );
        $this->readBuffer = '';
        $this->readOffset = 0;

        if (null !== $this->persistentId && '' !== $this->persistentId && $isTcp) {
            $this->verifyPersistentSocketSync();
        }
    }

    /**
     * When a {@code STREAM_CLIENT_PERSISTENT} fd is handed back to a fresh
     * PHP-FPM worker, the kernel-level receive buffer may still contain
     * leftover bytes from whichever request abandoned the socket last (e.g.
     * a partially-consumed response after a fatal error). Issue a single
     * {@code version} round-trip and if the reply doesn't look like a
     * memcached {@code VERSION} line — drop the socket and reconnect fresh.
     *
     * The {@see $writeBuffer} is intentionally *not* mutated here. Callers
     * (notably {@see bufferWrite()}) may have queued user commands before the
     * first {@code connect()} ran — flushing those mid-handshake would
     * silently drop them.
     */
    private function verifyPersistentSocketSync(): void
    {
        $socket = $this->socket;
        if (!\is_resource($socket)) {
            return;
        }

        $payload = "version\r\n";
        $sent = @fwrite($socket, $payload);
        if (false === $sent || $sent < \strlen($payload)) {
            @fclose($socket);
            $this->socket = null;
            $this->connectFresh();

            return;
        }

        $line = @fgets($socket);
        if (false === $line || !str_starts_with($line, 'VERSION ')) {
            @fclose($socket);
            $this->socket = null;
            $this->connectFresh();
        }
    }

    private function connectFresh(): void
    {
        $this->socket = $this->openSocket(
            'tcp://'.$this->host.':'.$this->port,
            \STREAM_CLIENT_CONNECT,
            \sprintf('Reconnect (after persistent desync) to %s:%d failed', $this->host, $this->port),
            true,
        );
        $this->readBuffer = '';
        $this->readOffset = 0;
    }

    /**
     * Single low-level entry point that {@see connect()} and
     * {@see connectFresh()} both use to materialise a socket. Centralises
     * the {@code stream_socket_client} invocation, the error-path
     * {@link ConnectionException}, post-connect blocking/timeout setup, and
     * the optional TCP-socket option application.
     *
     * @param int<0,7> $flags any bitmask of {@code STREAM_CLIENT_*} constants
     *
     * @return resource
     */
    private function openSocket(string $uri, int $flags, string $errorPrefix, bool $applyTcpSocketOptions)
    {
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'socket' => [
                'tcp_nodelay' => $this->tcpNoDelay,
                'so_keepalive' => $this->tcpKeepAlive,
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
            throw new ConnectionException(\sprintf('%s: %s (%s)', $errorPrefix, $msg, $connectErrno), $connectErrno);
        }

        stream_set_blocking($socket, true);
        if ($applyTcpSocketOptions) {
            $this->applyTcpSocketOptions($socket);
        }

        if (null !== $this->recvTimeoutUsec && $this->recvTimeoutUsec > 0) {
            stream_set_timeout($socket, intdiv($this->recvTimeoutUsec, 1_000_000), $this->recvTimeoutUsec % 1_000_000);
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function applyTcpSocketOptions($socket): void
    {
        if (!\function_exists('socket_import_stream') || !\function_exists('socket_set_option')) {
            return;
        }

        if (!\defined('SOL_SOCKET')) {
            return;
        }

        $importedSocket = @socket_import_stream($socket);
        if (!$importedSocket instanceof \Socket) {
            return;
        }

        if ($this->tcpKeepAlive && \defined('SO_KEEPALIVE')) {
            @socket_set_option($importedSocket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
        }

        if ($this->socketSendSize > 0 && \defined('SO_SNDBUF')) {
            @socket_set_option($importedSocket, \SOL_SOCKET, \SO_SNDBUF, $this->socketSendSize);
        }

        if ($this->socketRecvSize > 0 && \defined('SO_RCVBUF')) {
            @socket_set_option($importedSocket, \SOL_SOCKET, \SO_RCVBUF, $this->socketRecvSize);
        }

        if ($this->tcpCork) {
            $this->applyTcpCork($importedSocket);
        }
    }

    /**
     * Apply {@code TCP_CORK} (Linux) to the imported socket. Constant id 3 is
     * the canonical {@code TCP_CORK} value from {@code linux/tcp.h} — neither
     * PHP nor ext-sockets export it as a named constant, so we look it up at
     * runtime when {@code SOL_TCP} is available. macOS/BSD use {@code TCP_NOPUSH}
     * (id 4) with slightly different semantics; libmemcached treats {@code OPT_CORK}
     * as Linux-only, so we silently no-op there.
     */
    private function applyTcpCork(\Socket $socket): void
    {
        if (!\defined('SOL_TCP')) {
            return;
        }

        if ('Linux' !== \PHP_OS_FAMILY) {
            return;
        }

        // PHP 8.5+ exposes TCP_CORK as a named constant on Linux builds;
        // older versions still respect the bare integer.
        $cork = \defined('TCP_CORK') ? \TCP_CORK : 3;
        @socket_set_option($socket, \SOL_TCP, $cork, 1);
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
        if ($this->ioBytesWatermark > 0 && \strlen($this->writeBuffer) >= $this->ioBytesWatermark) {
            $this->flushWrite();
        }
    }

    /**
     * Returns the per-write {@code stream_select()} budget in microseconds.
     * {@code OPT_SEND_TIMEOUT} wins when set; otherwise falls back to
     * {@code OPT_POLL_TIMEOUT} (which libmemcached uses as the generic
     * I/O readiness budget). When neither is set we return {@code null} so
     * the caller skips {@code stream_select()} entirely (the same legacy
     * behaviour as before).
     */
    private function effectiveWriteWaitUsec(): ?int
    {
        if (null !== $this->sendTimeoutUsec && $this->sendTimeoutUsec > 0) {
            return $this->sendTimeoutUsec;
        }

        if ($this->pollTimeoutMs > 0) {
            return $this->pollTimeoutMs * 1000;
        }

        return null;
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
            $writeWaitUsec = $this->effectiveWriteWaitUsec();
            if (null !== $writeWaitUsec && $writeWaitUsec > 0) {
                $read = null;
                $write = [$socket];
                $except = null;
                $ready = @stream_select(
                    $read,
                    $write,
                    $except,
                    intdiv($writeWaitUsec, 1_000_000),
                    $writeWaitUsec % 1_000_000,
                );
                if (false === $ready || 0 === $ready) {
                    $this->writeBuffer = substr($buf, $off);
                    $this->close();
                    throw new TimeoutException('Write timeout to memcached');
                }
            }

            $n = @fwrite($socket, substr($buf, $off));
            if (false === $n) {
                $this->writeBuffer = substr($buf, $off);
                $err = error_get_last();
                $this->close();
                throw new ConnectionException('Write failure to memcached: '.($err['message'] ?? 'unknown'));
            }

            if (0 === $n) {
                $meta = stream_get_meta_data($socket);
                $this->writeBuffer = substr($buf, $off);
                $this->close();
                if ($meta['timed_out']) {
                    throw new TimeoutException('Write timeout to memcached');
                }

                $err = error_get_last();
                throw new ConnectionException('Write failure to memcached (wrote 0 bytes): '.($err['message'] ?? 'unknown'));
            }

            $off += $n;
        }

        $this->writeBuffer = '';
    }

    /**
     * Trims the consumed prefix of {@see $readBuffer}. The {@code 16 KiB}
     * threshold is a microopt that keeps tiny consumed chunks (eg. a 3-byte
     * status line) from triggering a substring per call; once the offset
     * actually catches up with the buffer length we still flush eagerly so we
     * don't carry an arbitrarily long, fully-consumed string across requests.
     */
    private function compactBuffer(): void
    {
        if ($this->readOffset >= \strlen($this->readBuffer)) {
            $this->readBuffer = '';
            $this->readOffset = 0;

            return;
        }

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

            $this->fillReadBufferChunk();
        }
    }

    /**
     * Like {@see readLine()}, but also accepts a lone LF terminator. Memcached's
     * {@code lru_crawler metadump} emits LF-terminated lines; the rest of the text
     * protocol uses CRLF.
     */
    public function readLineFlexible(): string
    {
        $this->flushWrite();
        $this->connect();
        while (true) {
            $buf = $this->readBuffer;
            $off = $this->readOffset;
            $crlfPos = strpos($buf, "\r\n", $off);
            $lfPos = strpos($buf, "\n", $off);

            if (false !== $crlfPos && (false === $lfPos || $crlfPos < $lfPos)) {
                $line = substr($buf, $off, $crlfPos - $off);
                $this->readOffset = $crlfPos + 2;
                $this->compactBuffer();

                return $line;
            }

            if (false !== $lfPos) {
                $line = substr($buf, $off, $lfPos - $off);
                if (str_ends_with($line, "\r")) {
                    $line = substr($line, 0, -1);
                }

                $this->readOffset = $lfPos + 1;
                $this->compactBuffer();

                return $line;
            }

            $this->fillReadBufferChunk();
        }
    }

    private function fillReadBufferChunk(): void
    {
        $socket = $this->socketResource();
        $chunk = fread($socket, 8192);
        if (false === $chunk || '' === $chunk) {
            $meta = stream_get_meta_data($socket);
            $this->close();
            if ($meta['timed_out']) {
                throw new TimeoutException('Read timeout');
            }

            $err = error_get_last();
            throw new ConnectionException('Connection closed while reading line: '.($err['message'] ?? 'unknown'));
        }

        $this->readBuffer .= $chunk;
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
                    throw new TimeoutException('Read timeout');
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
