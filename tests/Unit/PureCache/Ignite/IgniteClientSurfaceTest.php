<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteClient;

final class IgniteClientSurfaceTest extends TestCase
{
    public function testMaxKeyLengthMatchesIgniteLimits(): void
    {
        $client = new IgniteClient();
        self::assertSame(65_536, $client->maxKeyLength());
    }

    public function testItemSizeLimitCanBeConfiguredWithoutServers(): void
    {
        $client = new IgniteClient();
        self::assertTrue($client->setOption(IgniteClient::OPT_ITEM_SIZE_LIMIT, 4096));
        self::assertSame(4096, $client->getOption(IgniteClient::OPT_ITEM_SIZE_LIMIT));
    }

    public function testSetOversizedValueReturnsE2bigBeforeConnect(): void
    {
        $client = new IgniteClient();
        $client->addServer('127.0.0.1', 10800);
        $client->setOption(IgniteClient::OPT_ITEM_SIZE_LIMIT, 4);
        self::assertFalse($client->set('big', str_repeat('x', 32)));
        self::assertSame(IgniteClient::RES_E2BIG, $client->getResultCode());
    }
}
