<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Shared {@code get()} / {@code getByKey()} preflight and {@code cache_cb} handling.
 */
final readonly class ClientReadCoordinator
{
    /**
     * @param \Closure(): void                                $flushNetworkWrites
     * @param \Closure(string, string, ?string, int): mixed   $doGet
     * @param \Closure(callable, string, ?string, int): mixed $invokeCacheCb
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        private \Closure $flushNetworkWrites,
        private \Closure $doGet,
        private \Closure $invokeCacheCb,
    ) {
    }

    public function get(string $key, string $prefixedKey, ?string $serverKey, ?callable $cacheCb, int $getFlags): mixed
    {
        ($this->flushNetworkWrites)();

        if (!$this->env->checkKeyInternal($prefixedKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $serverKey && !$this->env->checkKeyInternal($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->routing->ensureServersAvailable()) {
            return false;
        }

        $value = ($this->doGet)($key, $prefixedKey, $serverKey, $getFlags);
        $code = $this->env->getResultCode();
        if (MemcachedConstants::RES_SUCCESS !== $code && MemcachedConstants::RES_NOTFOUND !== $code) {
            ClientObserverNotifier::notifyOperationFailure($this->env->core, 'get', $code, $key);
        }

        if (false !== $value || MemcachedConstants::RES_NOTFOUND !== $code) {
            return $value;
        }

        if (null !== $cacheCb) {
            return ($this->invokeCacheCb)($cacheCb, $key, $serverKey, $getFlags);
        }

        return false;
    }
}
