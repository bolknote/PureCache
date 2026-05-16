<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisStatsAsMemcached;
use PureCache\Redis\RedisStatsBackend;

final class RedisStatsAsMemcachedTest extends TestCase
{
    public function testGeneralCoercesFloatInfoFieldsAndEmptyKeyScan(): void
    {
        $backend = $this->backend(
            [
                'process_id' => 42.5,
                'cmdstat_get' => 'no_calls_field',
                'uptime_in_seconds' => '3600',
            ],
        );

        $stats = RedisStatsAsMemcached::general($backend, '*', ['count' => 0, 'firstKey' => null, 'complete' => true]);

        self::assertSame(42, $stats['pid']);
        self::assertSame(0, $stats['curr_items']);
    }

    public function testItemsUsesFallbackMemoryWhenScanIsIncomplete(): void
    {
        $backend = $this->backend(
            ['process_id' => 1, 'uptime_in_seconds' => 1],
        );

        $stats = RedisStatsAsMemcached::items(
            $backend,
            'prefix:*',
            ['count' => 3, 'firstKey' => 'k', 'complete' => false],
        );

        self::assertGreaterThan(0, $stats['items:1:mem_requested']);
    }

    public function testIntFromHandlesIntegerAndFloatValues(): void
    {
        $ref = new \ReflectionMethod(RedisStatsAsMemcached::class, 'intFrom');

        self::assertSame(7, $ref->invoke(null, 7));
        self::assertSame(9, $ref->invoke(null, 9.0));
    }

    public function testEstimateMemRequestedReturnsZeroForNonPositiveCount(): void
    {
        $ref = new \ReflectionMethod(RedisStatsAsMemcached::class, 'estimateMemRequestedBytes');
        $backend = $this->backend([]);

        self::assertSame(0, $ref->invoke(null, $backend, 0, true));
    }

    /**
     * @param array<string, mixed> $info
     */
    private function backend(array $info): RedisStatsBackend
    {
        $backend = $this->createMock(RedisStatsBackend::class);
        $backend->method('info')->willReturn($info);
        $backend->method('dbsize')->willReturn(0);
        $backend->method('scan')->willReturn([0, []]);
        $backend->method('object')->willReturn(null);

        return $backend;
    }
}
