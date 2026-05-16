<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * {@code setMulti*} key validation and server-pool preflight.
 */
final readonly class ClientStoreMultiCoordinator
{
    /**
     * @param \Closure(mixed): string  $keyToString
     * @param \Closure(string): string $prefixedKey
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        private \Closure $keyToString,
        private \Closure $prefixedKey,
    ) {
    }

    /**
     * @param array<mixed> $items
     */
    public function validate(?string $serverKey, array $items): bool
    {
        if (null !== $serverKey && !$this->env->checkKeyInternal($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        foreach (array_keys($items) as $k) {
            if (!$this->env->checkKeyInternal(($this->prefixedKey)(($this->keyToString)($k)))) {
                $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if ([] !== $items && !$this->routing->ensureServersAvailable()) {
            return false;
        }

        return true;
    }
}
