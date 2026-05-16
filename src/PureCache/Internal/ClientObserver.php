<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Optional hooks for production observability (metrics, logging, tracing).
 *
 * Install via {@see \PureCache\AbstractCacheClient::setClientObserver()}.
 * This is a PureCache extension — PECL {@see \Memcached} has no equivalent.
 */
interface ClientObserver
{
    public function onServerFailure(int $serverIndex, string $host, int $port, \Throwable $throwable): void;

    public function onServerRecovered(int $serverIndex, string $host, int $port): void;

    /**
     * Fired when a read path rejects an oversize payload ({@code RES_E2BIG}).
     */
    public function onItemTooBig(?string $key, int $bytes): void;

    /**
     * Fired when an operation finishes with a non-success {@code RES_*} code.
     *
     * @param non-empty-string $operation short label such as {@code get}, {@code set}, {@code delete}
     */
    public function onOperationFailure(string $operation, int $resultCode, ?string $key): void;
}
