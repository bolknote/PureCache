<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;

final class IgniteClientFailurePathsTest extends TestCase
{
    private function clientOnClosedPort(): IgniteClient
    {
        $client = new IgniteClient();
        $client->addServer('127.0.0.1', 9);
        $client->setOption(IgniteClient::OPT_CONNECT_TIMEOUT, 50);

        return $client;
    }

    public function testGetDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->get('k'));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testSetDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->set('k', 'v'));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testGetMultiDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->getMulti(['a']));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testDeleteDoesNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->delete('k'));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testAddReplaceAndIncrementDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->add('k', 'v'));
        self::assertFalse($client->replace('k', 'v'));
        self::assertFalse($client->increment('n', 1));
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

    public function testGetStatsDoesNotReturnSuccessPayload(): void
    {
        $client = $this->clientOnClosedPort();
        $stats = $client->getStats();
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
        if (\is_array($stats)) {
            self::assertFalse($stats['127.0.0.1:9'] ?? true);
        }
    }

    public function testTouchCasAndDelayedFetchDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->touch('k', 60));
        self::assertFalse($client->cas('1', 'k', 'v'));
        $client->getDelayed(['k']);
        self::assertFalse($client->fetchAll());
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }

    public function testAppendSetMultiAndGetAllKeysDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        $client->setOption(IgniteClient::OPT_COMPRESSION, false);
        self::assertFalse($client->append('k', 'x'));
        self::assertFalse($client->setMulti(['k' => 1]));
        self::assertFalse($client->getAllKeys());
    }

    public function testDecrementDeleteMultiAndVersionDoNotSucceed(): void
    {
        $client = $this->clientOnClosedPort();
        self::assertFalse($client->decrement('n', 1));
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->deleteMulti(['k'])['k'] ?? null);
        self::assertFalse($client->getVersion());
        self::assertNotSame(IgniteClient::RES_SUCCESS, $client->getResultCode());
    }
}
