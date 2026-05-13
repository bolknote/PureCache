<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Per-server TCP connections with optional write buffering.
 */
final class ConnectionManager
{
    /** @var array<int, StreamConnection> */
    private array $pool = [];

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly float $connectTimeout,
        private readonly ?int $recvTimeoutUsec,
        private readonly ?int $sendTimeoutUsec,
        private readonly ?string $persistentId = null,
        private readonly bool $tcpNoDelay = false,
        private readonly bool $tcpKeepAlive = false,
        private readonly int $socketSendSize = 0,
        private readonly int $socketRecvSize = 0,
    ) {
    }

    public function resetPool(): void
    {
        $this->flushAndClosePool();
    }

    public function get(int $serverIndex): StreamConnection
    {
        if (!isset($this->pool[$serverIndex])) {
            $s = $this->selector->getServers()[$serverIndex] ?? null;
            if (null === $s) {
                throw new \RuntimeException('Invalid server index');
            }

            $this->pool[$serverIndex] = new StreamConnection(
                $s['host'],
                $s['port'],
                $this->connectTimeout,
                $this->recvTimeoutUsec,
                $this->sendTimeoutUsec,
                $this->persistentId,
                $this->tcpNoDelay,
                $this->tcpKeepAlive,
                $this->socketSendSize,
                $this->socketRecvSize,
            );
        }

        return $this->pool[$serverIndex];
    }

    /**
     * @param callable(StreamConnection):void $fn
     */
    public function withConnection(int $serverIndex, callable $fn): void
    {
        $fn($this->get($serverIndex));
    }

    public function flushAllBuffers(): void
    {
        foreach ($this->pool as $c) {
            $c->flushWrite();
        }
    }

    public function closeAll(): void
    {
        $this->flushAndClosePool();
    }

    private function flushAndClosePool(): void
    {
        $error = null;
        try {
            $this->flushAllBuffers();
        } catch (\Throwable $throwable) {
            $error = $throwable;
        }

        foreach ($this->pool as $c) {
            $c->close();
        }

        $this->pool = [];

        if ($error instanceof \Throwable) {
            throw $error;
        }
    }
}
