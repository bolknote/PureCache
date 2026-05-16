<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;

final class MemcachedClientFailurePathsTest extends TestCase
{
    private function clientOnClosedPort(): MemcachedClient
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(MemcachedClient::OPT_CONNECT_TIMEOUT, 50);

        return $client;
    }

    public function testGetSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->get('k'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testSetSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->set('k', 'v'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testGetMultiSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->getMulti(['a']));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testDeleteSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->delete('k'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testIncrementSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->increment('n'));
        self::assertSame(MemcachedClient::RES_FAILURE, $client->getResultCode());
    }

    public function testAddReplaceAppendDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        self::assertFalse($client->add('k', 'v'));
        self::assertFalse($client->replace('k', 'v'));
        self::assertFalse($client->append('k', 'x'));
    }

    public function testByKeyOperationsDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->setByKey('route', 'k', 'v'));
        self::assertFalse($client->getByKey('route', 'k'));
    }

    public function testFlushDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->flush());
    }

    public function testGetStatsAndVersionFail(): void
    {
        $client = $this->clientOnClosedPort();
        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertFalse($stats['127.0.0.1:9'] ?? true);
        $versions = $client->getVersion();
        self::assertIsArray($versions);
        self::assertSame('', $versions['127.0.0.1:9'] ?? 'missing');
    }

    public function testTouchCasAndDelayedFetchDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->touch('k', 60));
        self::assertFalse($client->cas('1', 'k', 'v'));
        $client->getDelayed(['k']);
        self::assertFalse($client->fetchAll());
        self::assertNotSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testGetAllKeysAndSetMultiDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->getAllKeys());
        self::assertFalse($client->setMulti(['k' => 1]));
        self::assertNotSame(MemcachedClient::RES_SUCCESS, $client->deleteMulti(['k'])['k'] ?? null);
    }

    public function testDecrementAndPrependDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        self::assertFalse($client->decrement('n', 1));
        self::assertFalse($client->prepend('k', 'x'));
    }
}
