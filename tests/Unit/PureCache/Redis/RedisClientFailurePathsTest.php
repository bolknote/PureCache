<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisClient;

/**
 * Exercises {@see RedisClient} error paths without a live server (closed port).
 */
final class RedisClientFailurePathsTest extends TestCase
{
    private function clientOnClosedPort(): RedisClient
    {
        $client = new RedisClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(RedisClient::OPT_CONNECT_TIMEOUT, 50);

        return $client;
    }

    public function testGetSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->get('k'));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testSetSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->set('k', 'v'));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testDeleteSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->delete('k'));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testGetMultiSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->getMulti(['a', 'b']));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testIncrementSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->increment('n', 1));
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testGetStatsSurfacesPerServerFailure(): void
    {
        $client = $this->clientOnClosedPort();
        $stats = $client->getStats();
        self::assertIsArray($stats);
        self::assertFalse($stats['127.0.0.1:9'] ?? true);
    }

    public function testGetVersionSurfacesPerServerFailure(): void
    {
        $client = $this->clientOnClosedPort();
        $versions = $client->getVersion();
        self::assertIsArray($versions);
        self::assertSame('', $versions['127.0.0.1:9'] ?? 'missing');
    }

    public function testGetAllKeysSetsFailure(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->getAllKeys());
        self::assertSame(RedisClient::RES_FAILURE, $client->getResultCode());
    }

    public function testFetchWithoutDelayedQueueSetsFetchNotFinished(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->fetch());
        self::assertSame(RedisClient::RES_FETCH_NOTFINISHED, $client->getResultCode());
    }

    public function testAddReplaceAndCasDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->add('k', 'v'));
        self::assertFalse($client->replace('k', 'v'));
        self::assertFalse($client->cas('1', 'k', 'v'));
    }

    public function testAppendAndPrependDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->setOption(RedisClient::OPT_COMPRESSION, false);
        self::assertFalse($client->append('k', 'x'));
        self::assertFalse($client->prepend('k', 'x'));
    }

    public function testSetMultiDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->setMulti(['a' => 1]));
    }

    public function testByKeyOperationsDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->setByKey('route', 'k', 'v'));
        self::assertFalse($client->getByKey('route', 'k'));
        self::assertFalse($client->deleteByKey('route', 'k'));
    }

    public function testFlushDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->flush());
    }

    public function testTouchDecrementAndDeleteMultiDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->touch('k', 60));
        self::assertFalse($client->decrement('n', 1));
        self::assertNotSame(RedisClient::RES_SUCCESS, $client->deleteMulti(['k'])['k'] ?? null);
    }

    public function testDelayedFetchPathsDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->getDelayed(['k']);
        self::assertFalse($client->fetchAll());
        self::assertNotSame(RedisClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testMultiByKeyAndPrependDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->setOption(RedisClient::OPT_COMPRESSION, false);
        self::assertFalse($client->setMultiByKey('route', ['k' => 1]));
        self::assertFalse($client->getMultiByKey('route', ['k']));
        self::assertFalse($client->prependByKey('route', 'k', 'x'));
    }
}
