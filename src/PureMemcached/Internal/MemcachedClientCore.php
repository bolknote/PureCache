<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

/**
 * Mutable state shared by {@see \PureMemcached\Client\MemcachedClient} instances
 * when using the same persistent_id (PECL-style in-process pooling).
 */
final class MemcachedClientCore
{
    public ServerSelector $selector;

    public ConnectionManager $conn;

    /** @var array<int, mixed> */
    public array $options = [];

    public int $compressionThreshold = 2000;

    public float $compressionFactor = 1.30;

    public int $resultCode;

    public string $resultMessage = '';

    public int $lastErrorErrno = 0;

    /** @var array{host:string,port:int,weight:int,type:string}|null */
    public ?array $lastDisconnectedServer = null;

    /** @var list<array{keys:list<string>, serverKey:?string, withCas:bool}> */
    public array $delayedQueue = [];

    public int $delayedCursor = 0;

    /** @var list<array<string, mixed>>|null */
    public ?array $delayedResults = null;

    private ?string $persistentId = null;

    private function __construct()
    {
    }

    public static function createFresh(?string $persistentId = null): self
    {
        $c = new self();
        $c->persistentId = $persistentId;
        $c->selector = new ServerSelector();
        $c->options = ClientOptions::defaults();
        $c->resultCode = MemcachedConstants::RES_SUCCESS;
        $c->resultMessage = '';
        $c->rebuildConnectionManager();

        return $c;
    }

    public function rebuildConnectionManager(): void
    {
        if (isset($this->conn)) {
            $this->conn->closeAll();
        }

        $this->conn = new ConnectionManager(
            $this->selector,
            $this->optionInt(MemcachedConstants::OPT_CONNECT_TIMEOUT, 1000) / 1000,
            $this->optionInt(MemcachedConstants::OPT_RECV_TIMEOUT, 0) > 0 ? $this->optionInt(MemcachedConstants::OPT_RECV_TIMEOUT, 0) * 1000 : null,
            $this->optionInt(MemcachedConstants::OPT_SEND_TIMEOUT, 0) > 0 ? $this->optionInt(MemcachedConstants::OPT_SEND_TIMEOUT, 0) * 1000 : null,
            $this->persistentId,
        );
    }

    private function optionInt(int $option, int $default): int
    {
        $value = $this->options[$option] ?? $default;
        if (\is_int($value)) {
            return $value;
        }

        return $default;
    }
}
