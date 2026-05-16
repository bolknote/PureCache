<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * In-process delayed-fetch queue ({@code getDelayed} / {@code fetch} / {@code fetchAll}).
 *
 * @psalm-suppress MixedAssignment
 */
final readonly class ClientDelayedFetchCoordinator
{
    /**
     * @param \Closure(): void                                                          $flushNetworkWrites
     * @param \Closure(mixed): string                                                   $keyToString
     * @param \Closure(array<mixed>): list<string>                                      $keyStrings
     * @param \Closure(list<string>, ?string, bool, callable): bool                     $doGetDelayedValueCallback
     * @param \Closure(list<string>, ?string, bool): (list<array<string, mixed>>|false) $doFetchBatch
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        private \Closure $flushNetworkWrites,
        private \Closure $keyToString,
        private \Closure $keyStrings,
        private \Closure $doGetDelayedValueCallback,
        private \Closure $doFetchBatch,
    ) {
    }

    /**
     * @param array<mixed> $keys
     */
    public function enqueueDelayed(?string $serverKey, array $keys, bool $withCas, ?callable $valueCb): bool
    {
        if (null !== $serverKey && !$this->env->checkKeyInternal($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $keys) {
            $this->env->setResult(MemcachedConstants::RES_SUCCESS);

            return true;
        }

        foreach ($keys as $k) {
            if (!$this->env->checkKeyInternal($this->env->prefixedKey(($this->keyToString)($k)))) {
                $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->routing->ensureServersAvailable()) {
            return false;
        }

        if (null !== $valueCb) {
            return ($this->doGetDelayedValueCallback)(($this->keyStrings)($keys), $serverKey, $withCas, $valueCb);
        }

        $this->env->core->delayedQueue[] = [
            'keys' => ($this->keyStrings)($keys),
            'serverKey' => $serverKey,
            'withCas' => $withCas,
        ];
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }

    /**
     * @return list<array<string, mixed>>|null {@code null} when priming failed ({@see ClientCoordinatorEnv::getResultCode()} is set)
     */
    public function pullDelayedResultsBatch(): ?array
    {
        if (null !== $this->env->core->delayedResults) {
            return $this->env->core->delayedResults;
        }

        if (!$this->primeDelayedResults()) {
            return null;
        }

        return $this->env->core->delayedResults;
    }

    public function abortDelayedFetch(): void
    {
        $this->env->core->delayedResults = null;
        $this->env->core->delayedQueue = [];
        $this->env->core->delayedCursor = 0;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchOne(): array|false
    {
        if ([] === $this->env->core->delayedQueue && null === $this->env->core->delayedResults) {
            $this->env->setResult(MemcachedConstants::RES_FETCH_NOTFINISHED);

            return false;
        }

        $current = $this->pullDelayedResultsBatch();
        if (null === $current) {
            $this->abortDelayedFetch();

            return false;
        }

        if ($this->env->core->delayedCursor >= \count($current) && [] !== $this->env->core->delayedQueue) {
            $this->env->core->delayedResults = null;
            $this->env->core->delayedCursor = 0;
            $current = $this->pullDelayedResultsBatch();
            if (null === $current) {
                $this->abortDelayedFetch();

                return false;
            }
        }

        if ($this->env->core->delayedCursor >= \count($current)) {
            $this->env->setResult(MemcachedConstants::RES_END);

            return false;
        }

        $row = $current[$this->env->core->delayedCursor++];
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    public function fetchAll(): array|false
    {
        if ([] === $this->env->core->delayedQueue && null === $this->env->core->delayedResults) {
            $this->env->setResult(MemcachedConstants::RES_FETCH_NOTFINISHED);

            return false;
        }

        $batch = $this->pullDelayedResultsBatch();
        if (null === $batch) {
            $this->abortDelayedFetch();

            return false;
        }

        $all = \array_slice($batch, $this->env->core->delayedCursor);
        while ([] !== $this->env->core->delayedQueue) {
            $this->env->core->delayedResults = null;
            $this->env->core->delayedCursor = 0;
            $next = $this->pullDelayedResultsBatch();
            if (null === $next) {
                $this->abortDelayedFetch();

                return false;
            }

            $all = array_merge($all, $next);
        }

        $this->env->core->delayedResults = [];
        $this->env->core->delayedQueue = [];
        $this->env->core->delayedCursor = 0;
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return $all;
    }

    private function primeDelayedResults(): bool
    {
        $batch = array_shift($this->env->core->delayedQueue);
        if (null === $batch) {
            $this->env->core->delayedResults = [];

            return true;
        }

        ($this->flushNetworkWrites)();
        if (!$this->routing->ensureServersAvailable()) {
            $this->env->core->delayedResults = [];
            $this->env->core->delayedCursor = 0;

            return false;
        }

        $results = ($this->doFetchBatch)($batch['keys'], $batch['serverKey'], $batch['withCas']);
        if (false === $results) {
            $this->env->core->delayedResults = [];
            $this->env->core->delayedCursor = 0;

            return false;
        }

        $this->env->core->delayedResults = $results;
        $this->env->core->delayedCursor = 0;

        return true;
    }
}
