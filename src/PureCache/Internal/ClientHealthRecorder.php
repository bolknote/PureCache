<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\Memcached\Internal\TimeoutException;

/**
 * libmemcached-shaped server failure / recovery accounting.
 */
final readonly class ClientHealthRecorder
{
    public function __construct(private ClientCoordinatorEnv $env)
    {
    }

    public function recordServerFailure(?int $serverIndex, \Throwable $throwable): void
    {
        $core = $this->env->core;
        $core->lastErrorErrno = (int) $throwable->getCode();
        if (null === $serverIndex) {
            return;
        }

        $server = $core->selector->getServers()[$serverIndex] ?? null;
        if (null === $server) {
            return;
        }

        $core->lastDisconnectedServer = [
            'host' => $server['host'],
            'port' => $server['port'],
            'weight' => $server['weight'],
            'type' => ServerEndpoint::listType($server['host']),
        ];

        $isTimeout = $throwable instanceof TimeoutException
            || (false !== stripos($throwable->getMessage(), 'timeout'))
            || (false !== stripos($throwable->getMessage(), 'timed out'));
        $core->failureTracker->recordFailure($serverIndex, $isTimeout);

        $observer = $core->observer;
        if ($observer instanceof ClientObserver) {
            $observer->onServerFailure($serverIndex, $server['host'], $server['port'], $throwable);
        }
    }

    public function recordServerSuccess(?int $serverIndex): void
    {
        if (null === $serverIndex || $serverIndex < 0) {
            return;
        }

        $core = $this->env->core;
        $previous = $core->failureTracker->availability($serverIndex);
        $core->failureTracker->recordSuccess($serverIndex);

        if (ServerAvailability::Ok !== $previous) {
            $server = $core->selector->getServers()[$serverIndex] ?? null;
            $observer = $core->observer;
            if (null !== $server && $observer instanceof ClientObserver) {
                $observer->onServerRecovered($serverIndex, $server['host'], $server['port']);
            }
        }
    }
}
