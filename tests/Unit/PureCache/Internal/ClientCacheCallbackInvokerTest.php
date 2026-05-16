<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\CacheClient;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Memcached\MemcachedClient;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class ClientCacheCallbackInvokerTest extends TestCase
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

    public function testInvokeStoresCallbackValueAndReturnsItOnMiss(): void
    {
        $client = new MemcachedClient();
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);

        $invoker = $this->registry($client)->cacheCallback();

        $result = $invoker->invoke(
            static function (CacheClient $cbClient, string $key, mixed &$value, int &$expiration, float &$cas): bool {
                $value = 'from_cb';
                $expiration = 60;
                $cas = 0.0;

                return true;
            },
            'cb_key',
            null,
            0,
        );

        self::assertSame('from_cb', $result);
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
        self::assertSame('from_cb', $client->get('cb_key'));
    }

    public function testInvokeReturnsNotfoundWhenCallbackRejects(): void
    {
        $client = new MemcachedClient();
        $invoker = $this->registry($client)->cacheCallback();

        $result = $invoker->invoke(
            static fn (): bool => false,
            'missing',
            null,
            0,
        );

        self::assertFalse($result);
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testInvokeUsesByKeyPathWhenServerKeyProvided(): void
    {
        $client = new MemcachedClient();
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);
        $client->addServer('127.0.0.1', $port);

        $invoker = $this->registry($client)->cacheCallback();

        $result = $invoker->invoke(
            static function (CacheClient $cbClient, string $key, mixed &$value, int &$expiration, float &$cas): bool {
                $value = 'by_key';
                $expiration = 30;
                $cas = 0.0;

                return true;
            },
            'pool_key',
            '127.0.0.1:'.$port,
            0,
        );

        self::assertSame('by_key', $result);
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    private function registry(MemcachedClient $client): ClientCoordinatorRegistry
    {
        $method = new \ReflectionMethod($client, 'coordinators');
        $registry = $method->invoke($client);
        if (!$registry instanceof ClientCoordinatorRegistry) {
            throw new \LogicException('coordinators() must return ClientCoordinatorRegistry');
        }

        return $registry;
    }
}
