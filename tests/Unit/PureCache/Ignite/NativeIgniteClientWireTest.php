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

    public function testConnectCacheRoundTripOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        $client->connect();

        $cacheId = 1;
        $client->cachePut($cacheId, 'native-key', 'payload');
        self::assertSame('payload', $client->cacheGet($cacheId, 'native-key'));

        $all = $client->cacheGetAll($cacheId, ['native-key', 'missing']);
        self::assertArrayHasKey('native-key', $all);

        self::assertTrue($client->cacheRemoveKey($cacheId, 'native-key'));
        self::assertNull($client->cacheGet($cacheId, 'native-key'));

        $client->cachePut($cacheId, 'clear-me', 'x');
        $client->cacheClear($cacheId);
        self::assertGreaterThanOrEqual(0, $client->cacheGetSize($cacheId));

        $client->cacheScanKeys($cacheId);

        $client->disconnect();
    }

    public function testCachePutIfAbsentAndReplaceOnFakeIgnite(): void
    {
        $client = $this->clientOnFakeIgnite();
        $client->connect();

        $cacheId = 1;

        self::assertTrue($client->cachePutIfAbsent($cacheId, 'absent', 'v1'));
        self::assertFalse($client->cachePutIfAbsent($cacheId, 'absent', 'v2'));
        self::assertTrue($client->cacheReplace($cacheId, 'absent', 'v3'));
        self::assertTrue($client->cacheReplaceIfEquals($cacheId, 'absent', 'v3', 'v4'));

        $client->disconnect();
    }

    private function clientOnFakeIgnite(): NativeIgniteClient
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_ignite_store_server.php', [
            'FAKE_IGNITE_PORT' => (string) $port,
        ]);

        return new NativeIgniteClient('127.0.0.1', $port, 2.0);
    }
}
