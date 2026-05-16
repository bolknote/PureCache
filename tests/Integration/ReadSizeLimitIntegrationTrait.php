<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\CacheClient;
use PureCache\Memcached\MemcachedClient;

trait ReadSizeLimitIntegrationTrait
{
    abstract protected function createClient(): CacheClient;

    protected function assertReadRejectsOversizedStoredValue(int $storedBytes = 128, int $readLimit = 64): void
    {
        $key = 'pure_read_limit_'.bin2hex(random_bytes(8));
        $payload = str_repeat('x', $storedBytes);

        $writer = $this->createClient();
        $writer->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $writer->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 0);
        self::assertTrue($writer->set($key, $payload, 60));

        $reader = $this->createClient();
        $reader->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $reader->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, $readLimit);
        self::assertFalse($reader->get($key), 'oversized item must not be returned');
        self::assertSame(
            MemcachedClient::RES_E2BIG,
            $reader->getResultCode(),
            'expected RES_E2BIG, got '.$reader->getResultMessage(),
        );

        $writer->delete($key);
    }
}
