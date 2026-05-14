<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Mutable state shared by client instances that participate in PECL-style
 * in-process persistent pooling (same {@code persistent_id}). Subclasses add
 * backend-specific resources (connection pools, transport handles, etc.).
 */
abstract class ClientCoreState
{
    public ServerSelector $selector;

    /** @var array<int, mixed> */
    public array $options = [];

    public int $compressionThreshold = 2000;

    public float $compressionFactor = 1.30;

    public int $resultCode = MemcachedConstants::RES_SUCCESS;

    public string $resultMessage = '';

    public int $lastErrorErrno = 0;

    /** @var array{host:string,port:int,weight:int,type:string}|null */
    public ?array $lastDisconnectedServer = null;

    /** @var list<array{keys:list<string>, serverKey:?string, withCas:bool}> */
    public array $delayedQueue = [];

    public int $delayedCursor = 0;

    /** @var list<array<string, mixed>>|null */
    public ?array $delayedResults = null;

    public ?string $persistentId = null;

    protected function initDefaults(?string $persistentId): void
    {
        $this->persistentId = $persistentId;
        $this->selector = new ServerSelector();
        $this->options = ClientOptions::defaults();
        $this->resultCode = MemcachedConstants::RES_SUCCESS;
        $this->resultMessage = '';
    }

    public function optionInt(int $option, int $default): int
    {
        $value = $this->options[$option] ?? $default;
        if (\is_int($value)) {
            return $value;
        }

        return $default;
    }

    public function optionBool(int $option, bool $default): bool
    {
        $value = $this->options[$option] ?? $default;
        if (\is_bool($value)) {
            return $value;
        }

        return $default;
    }
}
