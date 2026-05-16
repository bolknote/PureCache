<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * {@code quit()} and {@code flushBuffers()} result handling.
 */
final readonly class ClientLifecycleHelper
{
    /**
     * @param \Closure(): void $onPoolInvalidated
     * @param \Closure(): void $flushNetworkWrites
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private \Closure $onPoolInvalidated,
        private \Closure $flushNetworkWrites,
    ) {
    }

    public function quit(): bool
    {
        try {
            ($this->onPoolInvalidated)();
        } catch (\Throwable $throwable) {
            $this->env->setResult(MemcachedConstants::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }

    public function flushBuffers(): bool
    {
        try {
            ($this->flushNetworkWrites)();
        } catch (\Throwable $throwable) {
            $this->env->setResult(MemcachedConstants::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }
}
