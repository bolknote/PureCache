<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Replica fan-out writes and {@code OPT_STORE_RETRY_COUNT} failover retries.
 *
 * @psalm-suppress MixedAssignment
 */
final readonly class ClientWriteCoordinator
{
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientRoutingCoordinator $routing,
        private ClientHealthRecorder $health,
    ) {
    }

    public function retryStoreOnFailure(?string $serverKey, string $key, \Closure $writer): bool
    {
        if ($this->writeFanout($serverKey, $key, $writer)) {
            return true;
        }

        $retryCount = $this->env->optionInt(MemcachedConstants::OPT_STORE_RETRY_COUNT, 0);
        $resultCode = $this->env->getResultCode();
        if ($retryCount <= 0 || MemcachedConstants::RES_FAILURE !== $resultCode) {
            return false;
        }

        $failureCode = $resultCode;
        $failureMessage = $this->env->core->resultMessage;
        $totalServers = \count($this->env->core->selector->getServers());
        if (0 === $totalServers) {
            return false;
        }

        $tried = [$this->routing->pickServerIndex($serverKey, $key)];
        for ($attempt = 0; $attempt < $retryCount; ++$attempt) {
            $live = $this->env->core->failureTracker->availableIndices($totalServers);
            $candidates = array_values(array_diff($live, $tried));
            if ([] === $candidates) {
                break;
            }

            $idx = $candidates[random_int(0, \count($candidates) - 1)];
            $tried[] = $idx;
            try {
                $writerResult = $writer($idx);
                if ($writerResult) {
                    $this->health->recordServerSuccess($idx);

                    return true;
                }

                if (MemcachedConstants::RES_FAILURE !== $this->env->getResultCode()) {
                    return false;
                }
            } catch (\Throwable $throwable) {
                $this->health->recordServerFailure($idx, $throwable);
            }
        }

        $this->env->setResult($failureCode, $failureMessage);

        return false;
    }

    public function writeFanout(?string $serverKey, string $key, \Closure $writer): bool
    {
        $replicas = $this->env->optionInt(MemcachedConstants::OPT_NUMBER_OF_REPLICAS, 0);
        $routingKey = $serverKey ?? $this->env->routingKey($key);
        $indices = $this->env->core->selector->pickReplicaIndices($routingKey, $replicas);
        if ([] === $indices) {
            $this->env->setResult(MemcachedConstants::RES_NO_SERVERS);

            return false;
        }

        $primaryIdx = $indices[0];
        $primaryOk = (bool) $writer($primaryIdx);
        if ($primaryOk) {
            $this->health->recordServerSuccess($primaryIdx);
        }

        $primaryCode = $this->env->core->resultCode;
        $primaryMsg = $this->env->core->resultMessage;

        for ($i = 1, $n = \count($indices); $i < $n; ++$i) {
            $replicaIdx = $indices[$i];
            try {
                $replicaOk = $writer($replicaIdx);
                if ($replicaOk) {
                    $this->health->recordServerSuccess($replicaIdx);
                }
            } catch (\Throwable $throwable) {
                $this->health->recordServerFailure($replicaIdx, $throwable);
            }
        }

        $this->env->setResult($primaryCode, $primaryMsg);

        return $primaryOk;
    }
}
