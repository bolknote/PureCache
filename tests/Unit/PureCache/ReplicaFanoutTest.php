<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

/**
 * Wire-free coverage of {@code OPT_NUMBER_OF_REPLICAS} and
 * {@code OPT_RANDOMIZE_REPLICA_READ}. We exercise the protected helpers via
 * reflection so the tests focus purely on the routing decisions made by
 * {@see \PureCache\AbstractCacheClient::writeFanout()} and
 * {@see \PureCache\AbstractCacheClient::pickReadServerIndex()}, without
 * touching the network.
 */
final class ReplicaFanoutTest extends TestCase
{
    public function testWriteFanoutInvokesPrimaryPlusReplicas(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 2);

        $hits = [];
        $ok = $this->callWriteFanout($client, null, 'item-7', function (int $idx) use (&$hits): bool {
            $hits[] = $idx;

            return true;
        });

        self::assertTrue($ok);
        self::assertCount(3, $hits);
        self::assertCount(3, array_unique($hits), 'replicas must be unique');
    }

    public function testWriteFanoutPreservesPrimaryResultEvenIfReplicaThrows(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 2);

        $calls = 0;
        $ok = $this->callWriteFanout($client, null, 'item', function () use (&$calls): bool {
            ++$calls;
            if ($calls >= 2) {
                throw new \RuntimeException('replica down');
            }

            return true;
        });

        self::assertTrue($ok, 'replica failures must not bubble');
        self::assertSame(MemcachedConstants::RES_SUCCESS, $client->getResultCode());
        self::assertSame(3, $calls, 'fanout must still attempt every replica');
    }

    public function testWriteFanoutReturnsFalseWhenPrimaryFails(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 1);

        $ok = $this->callWriteFanout($client, null, 'item', static fn (int $idx): bool => false);

        self::assertFalse($ok);
    }

    public function testWriteFanoutReportsNoServersWhenPoolIsEmpty(): void
    {
        $client = new MemcachedClient();
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 1);

        $ok = $this->callWriteFanout($client, null, 'item', static fn (): bool => true);

        self::assertFalse($ok);
        self::assertSame(MemcachedConstants::RES_NO_SERVERS, $client->getResultCode());
    }

    public function testRandomizedReadFallsBackToPrimaryWhenOptionsAreOff(): void
    {
        $client = $this->newClientWithThreeServers();

        $primary = $this->callPickServer($client, null, 'item');
        $read = $this->callPickRead($client, null, 'item');
        self::assertSame($primary, $read);
    }

    public function testRandomizedReadSpreadsAcrossReplicasWhenEnabled(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 2);
        $client->setOption(MemcachedClient::OPT_RANDOMIZE_REPLICA_READ, true);

        $seen = [];
        for ($i = 0; $i < 60; ++$i) {
            $seen[$this->callPickRead($client, null, 'spread')] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'expected reads to spread across replicas');
    }

    public function testWriteFanoutWithReplicasZeroBehavesLikeSingleWrite(): void
    {
        $client = $this->newClientWithThreeServers();

        $calls = 0;
        $ok = $this->callWriteFanout($client, null, 'item', function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        self::assertTrue($ok);
        self::assertSame(1, $calls, 'no replicas configured → primary only');
    }

    public function testRetryStoreOnFailureRetriesOnAnotherServerWhenPrimaryThrows(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_STORE_RETRY_COUNT, 2);

        $attempts = [];
        $ok = $this->callRetryStore($client, null, 'item', function (int $idx) use ($client, &$attempts): bool {
            $attempts[] = $idx;
            if (1 === \count($attempts)) {
                // Pretend the primary failed catastrophically. Set RES_FAILURE
                // explicitly so the retry helper considers it retriable.
                $rc = new \ReflectionMethod($client, 'setResult');
                $rc->invoke($client, MemcachedConstants::RES_FAILURE, 'down');

                return false;
            }

            return true;
        });

        self::assertTrue($ok);
        self::assertGreaterThanOrEqual(2, \count($attempts));
        self::assertCount(\count(array_unique($attempts)), $attempts, 'retry must pick a different server');
    }

    public function testRetryStoreOnFailureRespectsNonRetriableResults(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_STORE_RETRY_COUNT, 5);

        $calls = 0;
        $ok = $this->callRetryStore($client, null, 'item', function () use ($client, &$calls): bool {
            ++$calls;
            $rc = new \ReflectionMethod($client, 'setResult');
            // RES_NOTSTORED is a legitimate add()/cas() response, not a
            // transport failure — we must not retry.
            $rc->invoke($client, MemcachedConstants::RES_NOTSTORED, 'NOT STORED');

            return false;
        });

        self::assertFalse($ok);
        self::assertSame(1, $calls, 'non-RES_FAILURE outcome must not trigger retries');
    }

    public function testRetryStoreOnFailureStopsAfterRetryBudget(): void
    {
        $client = $this->newClientWithThreeServers();
        $client->setOption(MemcachedClient::OPT_STORE_RETRY_COUNT, 1);

        $attempts = 0;
        $ok = $this->callRetryStore($client, null, 'item', function () use ($client, &$attempts): bool {
            ++$attempts;
            $rc = new \ReflectionMethod($client, 'setResult');
            $rc->invoke($client, MemcachedConstants::RES_FAILURE, 'down');

            return false;
        });

        self::assertFalse($ok);
        self::assertSame(2, $attempts, 'primary + 1 retry = 2 attempts total');
    }

    private function callRetryStore(MemcachedClient $client, ?string $serverKey, string $key, \Closure $writer): bool
    {
        $rc = new \ReflectionMethod($client, 'retryStoreOnFailure');

        return (bool) $rc->invoke($client, $serverKey, $key, $writer);
    }

    private function newClientWithThreeServers(): MemcachedClient
    {
        $client = new MemcachedClient();
        $client->addServer('10.0.0.0', 11211);
        $client->addServer('10.0.0.1', 11211);
        $client->addServer('10.0.0.2', 11211);

        return $client;
    }

    private function callWriteFanout(MemcachedClient $client, ?string $serverKey, string $key, \Closure $writer): bool
    {
        $rc = new \ReflectionMethod($client, 'writeFanout');

        return (bool) $rc->invoke($client, $serverKey, $key, $writer);
    }

    private function callPickRead(MemcachedClient $client, ?string $serverKey, string $key): int
    {
        $rc = new \ReflectionMethod($client, 'pickReadServerIndex');

        return (int) $rc->invoke($client, $serverKey, $key);
    }

    private function callPickServer(MemcachedClient $client, ?string $serverKey, string $key): int
    {
        $rc = new \ReflectionMethod($client, 'pickServerIndex');

        return (int) $rc->invoke($client, $serverKey, $key);
    }
}
