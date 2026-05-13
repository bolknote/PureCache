<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

final class ServerSelector
{
    /** @var list<array{host:string,port:int,weight:int}> */
    private array $servers = [];

    private int $distribution = MemcachedConstants::DISTRIBUTION_MODULA;

    private bool $libketamaCompatible = false;

    private int $hashOption = 0;

    /** @var list<int>|null */
    private ?array $hostMap = null;

    /** @var list<int>|null */
    private ?array $forwardMap = null;

    private ?KetamaContinuum $ketama = null;

    public function reset(): void
    {
        $this->servers = [];
        $this->hostMap = null;
        $this->forwardMap = null;
        $this->ketama = null;
    }

    /**
     * @param array{host:string,port:int,weight:int} $server
     */
    public function addServer(array $server): void
    {
        $this->servers[] = $server;
        $this->ketama = null;
    }

    /**
     * @return list<array{host:string,port:int,weight:int}>
     */
    public function getServers(): array
    {
        return $this->servers;
    }

    public function setDistribution(int $d): void
    {
        $this->distribution = $d;
        $this->ketama = null;
    }

    public function setLibketamaCompatible(bool $v): void
    {
        $this->libketamaCompatible = $v;
        $this->ketama = null;
    }

    public function setHashOption(int $h): void
    {
        $this->hashOption = $h;
        $this->ketama = null;
    }

    /**
     * @param list<int>      $hostMap
     * @param list<int>|null $forwardMap same length as host map when not null (PECL / libmemcached virtual buckets)
     */
    public function setBucket(array $hostMap, int $replicas, ?array $forwardMap = null): void
    {
        $this->hostMap = $hostMap;
        $this->forwardMap = $forwardMap;
        $this->ketama = null;
    }

    public function clearBucket(): void
    {
        $this->hostMap = null;
        $this->forwardMap = null;
    }

    public function pickServerIndex(string $routingKey): int
    {
        if ([] === $this->servers) {
            return 0;
        }

        if (null !== $this->hostMap && [] !== $this->hostMap) {
            $h = KeyHasher::hash($routingKey, $this->effectiveHash());
            $slot = $h % \count($this->hostMap);
            $map = $this->forwardMap ?? $this->hostMap;
            $idx = $map[$slot];
            if ($idx < 0 || $idx >= \count($this->servers)) {
                return 0;
            }

            return $idx;
        }

        if (MemcachedConstants::DISTRIBUTION_CONSISTENT === $this->distribution || $this->libketamaCompatible) {
            $this->ketama ??= new KetamaContinuum(array_map(
                static fn (array $s): array => [$s['host'], $s['port'], $s['weight']],
                $this->servers,
            ), $this->effectiveHash(), $this->hasWeightedKetamaServers());
            $hk = KeyHasher::hash($routingKey, $this->effectiveHash());

            return $this->ketama->pick($hk);
        }

        return $this->pickModula($routingKey);
    }

    private function effectiveHash(): int
    {
        if ($this->libketamaCompatible) {
            return MemcachedConstants::HASH_MD5;
        }

        return $this->hashOption;
    }

    private function pickModula(string $routingKey): int
    {
        $h = KeyHasher::hash($routingKey, $this->hashOption);

        return $h % \count($this->servers);
    }

    private function hasWeightedKetamaServers(): bool
    {
        if (!$this->libketamaCompatible && MemcachedConstants::DISTRIBUTION_CONSISTENT !== $this->distribution) {
            return false;
        }

        foreach ($this->servers as $server) {
            if ($server['weight'] > 1) {
                return true;
            }
        }

        return false;
    }
}
