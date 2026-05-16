<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientObserver;
use PureCache\Internal\ClientObserverNotifier;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientObserverNotifierTest extends TestCase
{
    public function testNotifyItemTooBigNoOpsWithoutObserver(): void
    {
        $core = MemcachedClientCore::createFresh();
        ClientObserverNotifier::notifyItemTooBig($core, 'k', 99);
        self::assertNull($core->observer);
    }

    public function testNotifyOperationFailureSkipsSuccessAndEmptyOperation(): void
    {
        $core = MemcachedClientCore::createFresh();
        $observer = new class implements ClientObserver {
            public int $calls = 0;

            #[\Override]
            public function onItemTooBig(?string $key, int $bytes): void
            {
            }

            #[\Override]
            public function onOperationFailure(string $operation, int $resultCode, ?string $key): void
            {
                ++$this->calls;
            }

            #[\Override]
            public function onServerFailure(int $serverIndex, string $host, int $port, \Throwable $throwable): void
            {
            }

            #[\Override]
            public function onServerRecovered(int $serverIndex, string $host, int $port): void
            {
            }
        };
        $core->observer = $observer;

        ClientObserverNotifier::notifyOperationFailure($core, 'get', MemcachedConstants::RES_SUCCESS);
        ClientObserverNotifier::notifyOperationFailure($core, '', MemcachedConstants::RES_FAILURE);

        self::assertSame(0, $observer->calls);
    }

    public function testNotifyOperationFailureInvokesObserver(): void
    {
        $core = MemcachedClientCore::createFresh();
        $observer = new class implements ClientObserver {
            /** @var list<array{0: string, 1: int, 2: ?string}> */
            public array $events = [];

            #[\Override]
            public function onItemTooBig(?string $key, int $bytes): void
            {
            }

            #[\Override]
            public function onOperationFailure(string $operation, int $resultCode, ?string $key): void
            {
                $this->events[] = [$operation, $resultCode, $key];
            }

            #[\Override]
            public function onServerFailure(int $serverIndex, string $host, int $port, \Throwable $throwable): void
            {
            }

            #[\Override]
            public function onServerRecovered(int $serverIndex, string $host, int $port): void
            {
            }
        };
        $core->observer = $observer;

        ClientObserverNotifier::notifyOperationFailure($core, 'set', MemcachedConstants::RES_FAILURE, 'item');

        self::assertSame([['set', MemcachedConstants::RES_FAILURE, 'item']], $observer->events);
    }
}
