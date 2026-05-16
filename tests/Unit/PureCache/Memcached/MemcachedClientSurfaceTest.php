<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;

final class MemcachedClientSurfaceTest extends TestCase
{
    public function testItemSizeLimitRejectsOversizedSetBeforeWire(): void
    {
        $client = new MemcachedClient();
        $client->addServer('127.0.0.1', 11211);
        $client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 8);
        self::assertFalse($client->set('big', str_repeat('x', 32)));
        self::assertSame(MemcachedClient::RES_E2BIG, $client->getResultCode());
    }

    public function testMaxKeyLengthIsMemcachedDefault(): void
    {
        $client = new MemcachedClient();
        self::assertSame(250, $client->maxKeyLength());
    }
}
