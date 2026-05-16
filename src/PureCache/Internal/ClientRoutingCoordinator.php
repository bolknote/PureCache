<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Ketama/modula routing helpers shared by reads, writes, and multi-key batching.
 */
final readonly class ClientRoutingCoordinator
{
    public function __construct(private ClientCoordinatorEnv $env)
    {
    }

    public function ensureServersAvailable(): bool
    {
        if ([] !== $this->env->core->selector->getServers()) {
            return true;
        }

        $this->env->setResult(MemcachedConstants::RES_NO_SERVERS);

        return false;
    }

    public function pickServerIndex(?string $serverKey, string $key): int
    {
        return null !== $serverKey
            ? $this->env->core->selector->pickServerIndex($serverKey)
            : $this->env->core->selector->pickServerIndex($this->env->routingKey($key));
    }

    public function pickReadServerIndex(?string $serverKey, string $key): int
    {
        $replicas = $this->env->optionInt(MemcachedConstants::OPT_NUMBER_OF_REPLICAS, 0);
        if ($replicas <= 0 || !$this->env->optionBool(MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ, false)) {
            return $this->pickServerIndex($serverKey, $key);
        }

        $routingKey = $serverKey ?? $this->env->routingKey($key);
        $idx = $this->env->core->selector->pickReadIndex($routingKey, $replicas, true);

        return $idx < 0 ? $this->pickServerIndex($serverKey, $key) : $idx;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<int, list<array{0:string,1:string}>>
     */
    public function groupKeysByServer(array $keys, ?string $serverKey): array
    {
        $byServer = [];
        if (null === $serverKey) {
            foreach ($keys as $ks) {
                $idx = $this->env->core->selector->pickServerIndex($this->env->routingKey($ks));
                $byServer[$idx][] = [$ks, $this->env->prefixedKey($ks)];
            }

            return $byServer;
        }

        $idx = $this->env->core->selector->pickServerIndex($serverKey);
        foreach ($keys as $ks) {
            $byServer[$idx][] = [$ks, $this->env->prefixedKey($ks)];
        }

        return $byServer;
    }

    /**
     * @return array{primary: int, replicas: list<int>}|null
     */
    public function fanoutTargets(?string $serverKey, string $key): ?array
    {
        $replicas = $this->env->optionInt(MemcachedConstants::OPT_NUMBER_OF_REPLICAS, 0);
        $routingKey = $serverKey ?? $this->env->routingKey($key);
        $indices = $this->env->core->selector->pickReplicaIndices($routingKey, $replicas);
        if ([] === $indices) {
            return null;
        }

        return ['primary' => $indices[0], 'replicas' => \array_slice($indices, 1)];
    }
}
