<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * State machine that replicates libmemcached's per-server failure bookkeeping.
 *
 * Configuration knobs map 1:1 onto PECL options:
 *  - {@code OPT_SERVER_FAILURE_LIMIT}   → {@see setFailureLimit()}
 *  - {@code OPT_SERVER_TIMEOUT_LIMIT}   → {@see setTimeoutLimit()}
 *  - {@code OPT_RETRY_TIMEOUT}  (sec)   → {@see setRetryTimeoutSec()}
 *  - {@code OPT_DEAD_TIMEOUT}   (sec)   → {@see setDeadTimeoutSec()}
 *  - {@code OPT_REMOVE_FAILED_SERVERS}  → {@see setRemoveFailed()}
 *
 * The tracker stores indices into {@see ServerSelector::getServers()}, so it
 * is the selector's job to skip any index that resolves to a non-usable
 * availability state when routing.
 *
 * Time is injected via a clock closure so tests can advance "now" without
 * sleeping; production code uses {@see \microtime}.
 */
final class ServerFailureTracker
{
    /** @var array<int, int> */
    private array $consecutiveFailures = [];

    /** @var array<int, int> */
    private array $consecutiveTimeouts = [];

    /** @var array<int, float> */
    private array $lastFailureAt = [];

    /** @var array<int, float> */
    private array $deadUntil = [];

    /** @var array<int, true> */
    private array $removed = [];

    private int $failureLimit = 0;

    private int $timeoutLimit = 0;

    private int $retryTimeoutSec = 0;

    private int $deadTimeoutSec = 0;

    private bool $removeFailed = false;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock returns POSIX time in seconds (fractional). Defaults to {@see microtime(true)}
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function setFailureLimit(int $limit): void
    {
        $this->failureLimit = max(0, $limit);
    }

    public function setTimeoutLimit(int $limit): void
    {
        $this->timeoutLimit = max(0, $limit);
    }

    public function setRetryTimeoutSec(int $seconds): void
    {
        $this->retryTimeoutSec = max(0, $seconds);
    }

    public function setDeadTimeoutSec(int $seconds): void
    {
        $this->deadTimeoutSec = max(0, $seconds);
    }

    public function setRemoveFailed(bool $enabled): void
    {
        $this->removeFailed = $enabled;
        if (!$enabled) {
            foreach (array_keys($this->removed) as $idx) {
                unset($this->deadUntil[$idx], $this->consecutiveFailures[$idx], $this->consecutiveTimeouts[$idx], $this->lastFailureAt[$idx]);
            }

            $this->removed = [];
        }
    }

    public function recordFailure(int $serverIndex, bool $isTimeout = false): void
    {
        $now = ($this->clock)();
        $this->lastFailureAt[$serverIndex] = $now;
        $this->consecutiveFailures[$serverIndex] = ($this->consecutiveFailures[$serverIndex] ?? 0) + 1;
        if ($isTimeout) {
            $this->consecutiveTimeouts[$serverIndex] = ($this->consecutiveTimeouts[$serverIndex] ?? 0) + 1;
        }

        $failures = $this->consecutiveFailures[$serverIndex];
        $timeouts = $this->consecutiveTimeouts[$serverIndex] ?? 0;

        $failureLimitHit = $this->failureLimit > 0 && $failures >= $this->failureLimit;
        $timeoutLimitHit = $this->timeoutLimit > 0 && $timeouts >= $this->timeoutLimit;
        if (!$failureLimitHit && !$timeoutLimitHit) {
            return;
        }

        if ($this->removeFailed) {
            $this->removed[$serverIndex] = true;
            $this->deadUntil[$serverIndex] = \PHP_FLOAT_MAX;

            return;
        }

        if ($this->deadTimeoutSec > 0) {
            $this->deadUntil[$serverIndex] = $now + (float) $this->deadTimeoutSec;
        }
    }

    public function recordSuccess(int $serverIndex): void
    {
        unset(
            $this->consecutiveFailures[$serverIndex],
            $this->consecutiveTimeouts[$serverIndex],
            $this->lastFailureAt[$serverIndex],
            $this->deadUntil[$serverIndex],
        );
    }

    public function availability(int $serverIndex): ServerAvailability
    {
        if (isset($this->removed[$serverIndex])) {
            return ServerAvailability::DeadRemoved;
        }

        $now = ($this->clock)();
        $deadUntil = $this->deadUntil[$serverIndex] ?? 0.0;
        if ($deadUntil > $now) {
            return ServerAvailability::TemporarilyDisabled;
        }

        if ($deadUntil > 0.0 && $deadUntil <= $now) {
            // Dead window elapsed: half-open the server.
            unset($this->deadUntil[$serverIndex], $this->consecutiveFailures[$serverIndex], $this->consecutiveTimeouts[$serverIndex]);

            return ServerAvailability::Ok;
        }

        if ($this->retryTimeoutSec > 0 && isset($this->lastFailureAt[$serverIndex])) {
            $age = $now - $this->lastFailureAt[$serverIndex];
            if ($age >= 0 && $age < $this->retryTimeoutSec && ($this->consecutiveFailures[$serverIndex] ?? 0) > 0) {
                return ServerAvailability::RetryDelayed;
            }
        }

        return ServerAvailability::Ok;
    }

    public function isUsable(int $serverIndex): bool
    {
        return match ($this->availability($serverIndex)) {
            ServerAvailability::Ok, ServerAvailability::RetryDelayed => true,
            ServerAvailability::TemporarilyDisabled, ServerAvailability::DeadRemoved => false,
        };
    }

    /**
     * Indices that are not in {@code TemporarilyDisabled} / {@code DeadRemoved}.
     * Indices in {@code RetryDelayed} are still returned — the caller decides
     * whether to skip them for read load-balancing.
     *
     * @return list<int>
     */
    public function availableIndices(int $totalServers): array
    {
        $out = [];
        for ($i = 0; $i < $totalServers; ++$i) {
            if ($this->isUsable($i)) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * Forget every piece of state for {@code $serverIndex}. Called by
     * {@code resetServerList()} and by tests.
     */
    public function forget(int $serverIndex): void
    {
        unset(
            $this->consecutiveFailures[$serverIndex],
            $this->consecutiveTimeouts[$serverIndex],
            $this->lastFailureAt[$serverIndex],
            $this->deadUntil[$serverIndex],
            $this->removed[$serverIndex],
        );
    }

    public function forgetAll(): void
    {
        $this->consecutiveFailures = [];
        $this->consecutiveTimeouts = [];
        $this->lastFailureAt = [];
        $this->deadUntil = [];
        $this->removed = [];
    }
}
