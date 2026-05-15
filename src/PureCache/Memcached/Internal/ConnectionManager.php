<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

use PureCache\Internal\ServerSelector;

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
        private readonly bool $tcpCork = false,
        private readonly int $pollTimeoutMs = 1000,
        private readonly int $ioBytesWatermark = 0,
        private readonly int $ioMsgWatermark = 0,
        private readonly int $ioKeyPrefetch = 0,
        private readonly int $tcpKeepIdleSec = 0,
    ) {
    }

    public function ioBytesWatermark(): int
    {
        return $this->ioBytesWatermark;
    }

    public function ioMsgWatermark(): int
    {
        return $this->ioMsgWatermark;
    }

    public function ioKeyPrefetch(): int
    {
        return $this->ioKeyPrefetch;
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
                $this->tcpCork,
                $this->pollTimeoutMs,
                $this->ioBytesWatermark,
                $this->ioMsgWatermark,
                $this->tcpKeepIdleSec,
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

    /**
     * Flush every per-connection write buffer in the pool. Each per-server
     * failure is reported to {@code $onFailure} (so the caller can attribute it
     * to the right shard's failure tracker / {@code OPT_SERVER_FAILURE_LIMIT}
     * accounting) but the iteration keeps going so a single bad shard does not
     * block flushes on the rest of the pool. The first captured exception is
     * re-thrown at the end to preserve the legacy "flush failed → caller sees
     * an exception" contract.
     *
     * @param (callable(int, \Throwable): void)|null $onFailure
     */
    public function flushAllBuffers(?callable $onFailure = null): void
    {
        $firstError = null;
        foreach ($this->pool as $serverIndex => $connection) {
            try {
                $connection->flushWrite();
            } catch (\Throwable $throwable) {
                if (null !== $onFailure) {
                    $onFailure($serverIndex, $throwable);
                }

                $firstError ??= $throwable;
            }
        }

        if ($firstError instanceof \Throwable) {
            throw $firstError;
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
