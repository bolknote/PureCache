<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\NativeIgniteClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class NativeIgniteClientWireTest extends TestCase
{
    use FakeWireWorkerTrait;

    /** @var list<resource> */
    private array $wireWorkers = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->wireWorkers as $process) {
            $this->stopFakeWireWorker($process);
        }

        $this->wireWorkers = [];
        parent::tearDown();
    }

    public function testCacheLifecycleOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        $cacheId = $client->getOrCreateCache('PURECACHE_V1');

        self::assertNull($client->cacheGet($cacheId, 'native-miss'));
        $client->cachePut($cacheId, 'native-key', 'payload');
        self::assertSame('payload', $client->cacheGet($cacheId, 'native-key'));

        self::assertFalse($client->cachePutIfAbsent($cacheId, 'native-key', 'other'));
        self::assertTrue($client->cachePutIfAbsent($cacheId, 'fresh', 'v'));
        self::assertSame('v', $client->cacheGet($cacheId, 'fresh'));

        self::assertTrue($client->cacheReplace($cacheId, 'fresh', 'v2'));
        self::assertFalse($client->cacheReplace($cacheId, 'ghost', 'x'));

        self::assertTrue($client->cacheRemoveKey($cacheId, 'fresh'));
        self::assertFalse($client->cacheRemoveKey($cacheId, 'fresh'));

        $client->cachePut($cacheId, 'a', '1');
        $client->cachePut($cacheId, 'b', '2');

        $all = $client->cacheGetAll($cacheId, ['a', 'b', 'c']);
        self::assertSame(['a' => '1', 'b' => '2'], $all);

        self::assertGreaterThanOrEqual(1, $client->cacheGetSize($cacheId));
        self::assertContains('a', $client->cacheScanKeys($cacheId, 64));

        $client->cacheClear($cacheId);
        self::assertSame(0, $client->cacheGetSize($cacheId));

        $version = $client->resolveProductVersion($cacheId);
        self::assertNotSame('', $version);

        $stats = $client->getStatsSnapshot();
        self::assertNotSame('', $stats->serverVersion);
        self::assertGreaterThan(0, $stats->totalOps());

        $client->disconnect();
    }

    public function testReplaceIfEqualsOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        $cacheId = $client->getOrCreateCache('PURECACHE_V1');
        $client->cachePut($cacheId, 'cas-native', 'v1');
        self::assertTrue($client->cacheReplaceIfEquals($cacheId, 'cas-native', 'v1', 'v2'));
        self::assertFalse($client->cacheReplaceIfEquals($cacheId, 'cas-native', 'wrong', 'v3'));
        self::assertSame('v2', $client->cacheGet($cacheId, 'cas-native'));
    }

    private function clientOnFakeIgnite(): NativeIgniteClient
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
        ]);

        $client = new NativeIgniteClient('127.0.0.1', $port, 3.0);
        $client->connect();

        return $client;
    }
}
