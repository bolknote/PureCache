<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Shared {@code doDelete()} preflight ({@code bad key > delete-time > no servers}).
 */
final readonly class ClientDeleteCoordinator
{
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        /** @var \Closure(string): string */
        private \Closure $prefixedKey,
    ) {
    }

    /**
     * @param \Closure(string $prefixedKey): bool $body
     */
    public function execute(string $key, ?string $serverKey, int $time, \Closure $body): bool
    {
        $pk = ($this->prefixedKey)($key);
        if (!$this->env->checkKeyInternal($pk) || (null !== $serverKey && !$this->env->checkKeyInternal($serverKey))) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->acceptDeleteTime($time)) {
            return false;
        }

        if (!$this->routing->ensureServersAvailable()) {
            return false;
        }

        return $body($pk);
    }

    public function acceptDeleteTime(int $time): bool
    {
        if ($time < 0) {
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS, 'delete time must be non-negative');

            return false;
        }

        if ($time > 0) {
            $this->env->setResult(MemcachedConstants::RES_NOT_SUPPORTED, 'delayed delete is not supported by the meta protocol');

            return false;
        }

        return true;
    }
}
