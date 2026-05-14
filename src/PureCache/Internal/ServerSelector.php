<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

final class ServerSelector
{
    /** @var list<array{host:string,port:int,weight:int,user?:string,password?:string,database?:int}> */
    private array $servers = [];

    private int $distribution = MemcachedConstants::DISTRIBUTION_MODULA;

    private bool $libketamaCompatible = false;

    private int $hashOption = 0;

    private bool $sortHosts = false;

    /** @var list<int>|null */
    private ?array $hostMap = null;

    /** @var list<int>|null */
    private ?array $forwardMap = null;

    private ?KetamaContinuum $ketama = null;

    private ?ServerFailureTracker $failureTracker = null;

    /** @var \Closure(int): int */
    private \Closure $rng;

    public function __construct()
    {
        $this->rng = static fn (int $max): int => 0 < $max ? random_int(0, $max) : 0;
    }

    public function reset(): void
    {
        $this->servers = [];
        $this->hostMap = null;
        $this->forwardMap = null;
        $this->ketama = null;
        if ($this->failureTracker instanceof ServerFailureTracker) {
            $this->failureTracker->forgetAll();
        }
    }

    /**
     * @param array{host:string,port:int,weight:int,user?:string,password?:string,database?:int} $server
     */
    public function addServer(array $server): void
    {
        $this->servers[] = $server;
        $this->applyHostSort();
        $this->ketama = null;
    }

    /**
     * @return list<array{host:string,port:int,weight:int,user?:string,password?:string,database?:int}>
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

    public function setSortHosts(bool $enabled): void
    {
        $previous = $this->sortHosts;
        $this->sortHosts = $enabled;
        if (!$previous && $enabled) {
            $this->applyHostSort();
            $this->ketama = null;
        }
    }

    public function isSortHosts(): bool
    {
        return $this->sortHosts;
    }

    public function setFailureTracker(ServerFailureTracker $tracker): void
    {
        $this->failureTracker = $tracker;
    }

    public function getFailureTracker(): ?ServerFailureTracker
    {
        return $this->failureTracker;
    }

    /**
     * @param \Closure(int): int $rng called as {@code $rng($max)} → random integer in {@code [0,$max]} (inclusive)
     */
    public function setRng(\Closure $rng): void
    {
        $this->rng = $rng;
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

    /**
     * Routing entry-point that preserves PECL's historical "always return an
     * index" contract — callers that don't care about availability state keep
     * using this. Honours the failure tracker by skipping over
     * {@code TemporarilyDisabled}/{@code DeadRemoved} servers via
     * {@see pickServer()}.
     */
    public function pickServerIndex(string $routingKey): int
    {
        $pick = $this->pickServer($routingKey);

        return max(0, $pick->index);
    }

    /**
     * Like {@see pickServerIndex()} but exposes the availability of the
     * resolved server so callers can surface
     * {@code RES_SERVER_TEMPORARILY_DISABLED} / {@code RES_NO_SERVERS}.
     */
    public function pickServer(string $routingKey): ServerPick
    {
        $count = \count($this->servers);
        if (0 === $count) {
            return new ServerPick(-1, ServerAvailability::DeadRemoved);
        }

        if (null !== $this->hostMap && [] !== $this->hostMap) {
            $h = KeyHasher::hash($routingKey, $this->effectiveHash());
            $slot = $h % \count($this->hostMap);
            $map = $this->forwardMap ?? $this->hostMap;
            $idx = $map[$slot];
            if ($idx < 0 || $idx >= $count) {
                $idx = 0;
            }

            return $this->resolveAvailability($idx, fn (int $i): int => $this->modulaWalkToLive($routingKey, $i));
        }

        if (MemcachedConstants::DISTRIBUTION_CONSISTENT === $this->distribution || $this->libketamaCompatible) {
            $idx = $this->pickKetamaWithSalt($routingKey, 0);

            return $this->resolveAvailability($idx, fn (int $i): int => $this->ketamaWalkToLive($routingKey, $i));
        }

        $idx = $this->pickModula($routingKey);

        return $this->resolveAvailability($idx, fn (int $i): int => $this->modulaWalkToLive($routingKey, $i));
    }

    /**
     * Ordered list of unique server indices that should receive a write,
     * including the primary. {@code $replicas} mirrors
     * {@code OPT_NUMBER_OF_REPLICAS} — i.e. number of *additional* copies on
     * top of the primary. The returned list never contains duplicates and
     * never exceeds the live server count.
     *
     * @return list<int>
     */
    public function pickReplicaIndices(string $routingKey, int $replicas): array
    {
        $count = \count($this->servers);
        if (0 === $count) {
            return [];
        }

        $live = $this->failureTracker instanceof ServerFailureTracker
            ? $this->failureTracker->availableIndices($count)
            : range(0, $count - 1);
        if ([] === $live) {
            return [];
        }

        $primary = $this->pickServer($routingKey);
        if (!$primary->isUsable()) {
            return [];
        }

        $out = [$primary->index];
        if ($replicas <= 0 || 1 === \count($live)) {
            return $out;
        }

        if (MemcachedConstants::DISTRIBUTION_CONSISTENT === $this->distribution || $this->libketamaCompatible) {
            return $this->ketamaReplicas($routingKey, $replicas, $live, $out);
        }

        return $this->modulaReplicas($routingKey, $replicas, $live, $out);
    }

    /**
     * Read-side helper: pick the primary (or a randomly chosen replica when
     * {@code OPT_RANDOMIZE_REPLICA_READ=true}) for a routing key. Replica
     * candidates that are not currently usable are skipped silently.
     */
    public function pickReadIndex(string $routingKey, int $replicas, bool $randomize): int
    {
        $indices = $this->pickReplicaIndices($routingKey, $replicas);
        if ([] === $indices) {
            return -1;
        }

        if (!$randomize || 1 === \count($indices)) {
            return $indices[0];
        }

        $rng = $this->rng;

        return $indices[$rng(\count($indices) - 1)];
    }

    /**
     * @param \Closure(int): int $walkToLive returns a usable server index, or {@code -1} when none is available
     */
    private function resolveAvailability(int $idx, \Closure $walkToLive): ServerPick
    {
        $tracker = $this->failureTracker;
        if (!$tracker instanceof ServerFailureTracker) {
            return new ServerPick($idx);
        }

        $availability = $tracker->availability($idx);

        if (ServerAvailability::Ok === $availability || ServerAvailability::RetryDelayed === $availability) {
            return new ServerPick($idx, $availability);
        }

        $alternative = $walkToLive($idx);
        if ($alternative === $idx || $alternative < 0) {
            return new ServerPick($idx, $availability);
        }

        $altAvail = $tracker->availability($alternative);

        return new ServerPick($alternative, $altAvail);
    }

    /**
     * Walk the modula ring starting from the next index, returning the first
     * index whose tracker availability is usable. Returns {@code -1} if none.
     */
    private function modulaWalkToLive(string $routingKey, int $start): int
    {
        $count = \count($this->servers);
        if (!$this->failureTracker instanceof ServerFailureTracker || 0 === $count) {
            return $start;
        }

        $live = $this->failureTracker->availableIndices($count);
        if ([] === $live) {
            return -1;
        }

        if (\in_array($start, $live, true)) {
            return $start;
        }

        $h = KeyHasher::hash($routingKey, $this->hashOption);
        $rotation = $h % \count($live);

        return $live[$rotation];
    }

    /**
     * Ketama equivalent of {@see modulaWalkToLive()} — re-salts the routing
     * key until the hashed slot lands on a usable server. Falls back to a
     * deterministic scan of {@code $live} after a generous number of attempts
     * so a degenerate corner case can never spin.
     */
    private function ketamaWalkToLive(string $routingKey, int $start): int
    {
        $count = \count($this->servers);
        if (!$this->failureTracker instanceof ServerFailureTracker || 0 === $count) {
            return $start;
        }

        $live = $this->failureTracker->availableIndices($count);
        if ([] === $live) {
            return -1;
        }

        if ($this->failureTracker->isUsable($start)) {
            return $start;
        }

        for ($salt = 1; $salt < 64; ++$salt) {
            $candidate = $this->pickKetamaWithSalt($routingKey, $salt);
            if ($this->failureTracker->isUsable($candidate)) {
                return $candidate;
            }
        }

        $h = KeyHasher::hash($routingKey, $this->effectiveHash());

        return $live[$h % \count($live)];
    }

    private function pickKetamaWithSalt(string $routingKey, int $salt): int
    {
        $this->ketama ??= new KetamaContinuum(array_map(
            static fn (array $s): array => [$s['host'], $s['port'], $s['weight']],
            $this->servers,
        ), $this->effectiveHash(), $this->hasWeightedKetamaServers());
        $key = 0 === $salt ? $routingKey : $routingKey.'#'.$salt;
        $hk = KeyHasher::hash($key, $this->effectiveHash());

        return $this->ketama->pick($hk);
    }

    /**
     * @param list<int> $live
     * @param list<int> $out  pre-seeded with the primary index
     *
     * @return list<int>
     */
    private function ketamaReplicas(string $routingKey, int $replicas, array $live, array $out): array
    {
        $maxOut = min(\count($live), $replicas + 1);
        $seen = array_flip($out);
        $salt = 1;
        while (\count($out) < $maxOut && $salt < 256) {
            $candidate = $this->pickKetamaWithSalt($routingKey, $salt++);
            if (isset($seen[$candidate])) {
                continue;
            }

            if (!\in_array($candidate, $live, true)) {
                continue;
            }

            $out[] = $candidate;
            $seen[$candidate] = true;
        }

        if (\count($out) < $maxOut) {
            foreach ($live as $idx) {
                if (!isset($seen[$idx])) {
                    $out[] = $idx;
                    $seen[$idx] = true;
                    if (\count($out) === $maxOut) {
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $live
     * @param list<int> $out  pre-seeded with the primary index
     *
     * @return list<int>
     */
    private function modulaReplicas(string $routingKey, int $replicas, array $live, array $out): array
    {
        $maxOut = min(\count($live), $replicas + 1);
        $seen = array_flip($out);
        $h = KeyHasher::hash($routingKey, $this->hashOption);
        $primaryPos = false;
        foreach ($live as $pos => $idx) {
            if ($idx === $out[0]) {
                $primaryPos = $pos;
                break;
            }
        }

        if (false === $primaryPos) {
            $primaryPos = $h % \count($live);
        }

        $liveCount = \count($live);
        for ($step = 1; $step < $liveCount && \count($out) < $maxOut; ++$step) {
            $idx = $live[($primaryPos + $step) % $liveCount];
            if (isset($seen[$idx])) {
                continue;
            }

            $out[] = $idx;
            $seen[$idx] = true;
        }

        return $out;
    }

    private function applyHostSort(): void
    {
        if (!$this->sortHosts || [] === $this->servers) {
            return;
        }

        usort($this->servers, static function (array $a, array $b): int {
            $hostCmp = strcmp($a['host'], $b['host']);
            if (0 !== $hostCmp) {
                return $hostCmp;
            }

            return $a['port'] <=> $b['port'];
        });
        $this->ketama = null;
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
