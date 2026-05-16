<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ClientObserver;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;

final class ClientObserverTest extends TestCase
{
    public function testSetAndGetClientObserver(): void
    {
        $client = new MemcachedClient();
        $observer = new RecordingClientObserver();
        $client->setClientObserver($observer);
        self::assertSame($observer, $client->getClientObserver());

        $client->setClientObserver(null);
        self::assertNull($client->getClientObserver());
    }

    public function testHealthRecorderNotifiesObserverOnFailureAndRecovery(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'shard-a', 'port' => 11211, 'weight' => 0]);

        $spy = new RecordingClientObserver();
        $core->observer = $spy;
        $core->failureTracker->setFailureLimit(1);
        $core->failureTracker->setRetryTimeoutSec(60);

        $env = new ClientCoordinatorEnv(
            $core,
            static function (int $_code, ?string $_message = null): void {},
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => $core->optionInt($option, $default),
            static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
            static fn (string $_key): string => 'k',
            static fn (string $_key): string => 'k',
            static fn (string $_key): bool => true,
        );

        $recorder = new ClientHealthRecorder($env);
        $recorder->recordServerFailure(0, new \RuntimeException('timeout', 0));
        $recorder->recordServerSuccess(0);

        self::assertSame(['failure:shard-a', 'recovered:shard-a'], $spy->events);
    }

    public function testSetResultE2bigNotifiesItemTooBigObserver(): void
    {
        $client = new MemcachedClient();
        $spy = new RecordingClientObserver();
        $client->setClientObserver($spy);
        $client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 8);
        $client->set('big', str_repeat('x', 32));

        self::assertSame(MemcachedClient::RES_E2BIG, $client->getResultCode());
        self::assertContains('e2big::0', $spy->events);
    }

    public function testGetAgainstUnreachableServerInvokesFailureObserver(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 9);

        $spy = new RecordingClientObserver();
        $client->setClientObserver($spy);
        $client->setOption(MemcachedClient::OPT_SERVER_FAILURE_LIMIT, 1);

        self::assertFalse($client->get('observer_probe'));
        self::assertCount(1, $spy->failures);
        self::assertSame('127.0.0.1', $spy->failures[0]['host']);
        self::assertSame(9, $spy->failures[0]['port']);
    }
}

final class RecordingClientObserver implements ClientObserver
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array{host:string, port:int}> */
    public array $failures = [];

    #[\Override]
    public function onServerFailure(int $serverIndex, string $host, int $port, \Throwable $throwable): void
    {
        $this->events[] = 'failure:'.$host;
        $this->failures[] = ['host' => $host, 'port' => $port];
    }

    #[\Override]
    public function onServerRecovered(int $serverIndex, string $host, int $port): void
    {
        $this->events[] = 'recovered:'.$host;
    }

    #[\Override]
    public function onItemTooBig(?string $key, int $bytes): void
    {
        $this->events[] = 'e2big:'.($key ?? '').':'.$bytes;
    }

    #[\Override]
    public function onOperationFailure(string $operation, int $resultCode, ?string $key): void
    {
        $this->events[] = 'op:'.$operation.':'.$resultCode.':'.($key ?? '');
    }
}
