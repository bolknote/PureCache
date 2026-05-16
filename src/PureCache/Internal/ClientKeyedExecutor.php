<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Pre-flight + single-shard and replica-aware keyed operation execution.
 */
final readonly class ClientKeyedExecutor
{
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        private ClientHealthRecorder $health,
    ) {
    }

    /**
     * @template TResult
     *
     * @param \Closure(int $serverIndex, string $prefixedKey): TResult $body
     * @param TResult                                                  $failureValue
     *
     * @return TResult
     */
    public function executeKeyed(
        string $key,
        ?string $serverKey,
        \Closure $body,
        mixed $failureValue = false,
        bool $forRead = false,
        bool $fanoutWrite = false,
    ): mixed {
        $pk = $this->env->prefixedKey($key);
        if (!$this->env->checkKeyInternal($pk) || (null !== $serverKey && !$this->env->checkKeyInternal($serverKey))) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return $failureValue;
        }

        if (!$this->routing->ensureServersAvailable()) {
            return $failureValue;
        }

        if ($fanoutWrite) {
            return $this->executeKeyedFanout($key, $serverKey, $pk, $body, $failureValue);
        }

        $idx = $forRead
            ? $this->routing->pickReadServerIndex($serverKey, $key)
            : $this->routing->pickServerIndex($serverKey, $key);

        try {
            $result = $body($idx, $pk);
            $this->health->recordServerSuccess($idx);

            return $result;
        } catch (\Throwable $throwable) {
            $this->health->recordServerFailure($idx, $throwable);
            $this->env->setResult(MemcachedConstants::RES_FAILURE, $throwable->getMessage());

            return $failureValue;
        }
    }

    /**
     * @template TResult
     *
     * @param \Closure(int $serverIndex, string $prefixedKey): TResult $body
     * @param TResult                                                  $failureValue
     *
     * @return TResult
     */
    private function executeKeyedFanout(
        string $key,
        ?string $serverKey,
        string $prefixedKey,
        \Closure $body,
        mixed $failureValue,
    ): mixed {
        $replicas = $this->env->optionInt(MemcachedConstants::OPT_NUMBER_OF_REPLICAS, 0);
        $routingKey = $serverKey ?? $this->env->routingKey($key);
        $indices = $this->env->core->selector->pickReplicaIndices($routingKey, $replicas);
        if ([] === $indices) {
            $this->env->setResult(MemcachedConstants::RES_NO_SERVERS);

            return $failureValue;
        }

        $primaryIdx = $indices[0];
        try {
            $result = $body($primaryIdx, $prefixedKey);
            $this->health->recordServerSuccess($primaryIdx);
        } catch (\Throwable $throwable) {
            $this->health->recordServerFailure($primaryIdx, $throwable);
            $this->env->setResult(MemcachedConstants::RES_FAILURE, $throwable->getMessage());

            return $failureValue;
        }

        $primaryCode = $this->env->core->resultCode;
        $primaryMsg = $this->env->core->resultMessage;

        for ($i = 1, $n = \count($indices); $i < $n; ++$i) {
            $replicaIdx = $indices[$i];
            try {
                $body($replicaIdx, $prefixedKey);
                $this->health->recordServerSuccess($replicaIdx);
            } catch (\Throwable $throwable) {
                $this->health->recordServerFailure($replicaIdx, $throwable);
            }
        }

        $this->env->setResult($primaryCode, $primaryMsg);

        return $result;
    }
}
