<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisStatsAsMemcached;
use PureCache\Redis\RedisStatsBackend;

final class RedisStatsAsMemcachedTest extends TestCase
{
    public function testSizesMatchesMemcachedDisabledHistogram(): void
    {
        self::assertSame(['sizes_status' => 'disabled'], RedisStatsAsMemcached::sizes());
    }

    public function testGeneralMapsNestedInfoAndMergesMemory(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Server' => [
                'redis_version' => '7.2.1',
                'process_id' => '100',
                'uptime_in_seconds' => '3600',
                'tcp_port' => '6379',
            ],
            'Clients' => [
                'connected_clients' => '3',
            ],
            'Stats' => [
                'total_connections_received' => '10',
                'rejected_connections' => '0',
                'keyspace_hits' => '40',
                'keyspace_misses' => '2',
                'total_net_input_bytes' => '1000',
                'total_net_output_bytes' => '2000',
                'evicted_keys' => '0',
                'expired_keys' => '1',
            ],
            'Commandstats' => [
                'cmdstat_hgetall' => 'calls=5,usec=10',
                'cmdstat_hset' => 'calls=9,usec=20',
                'cmdstat_del' => 'calls=1,usec=1',
            ],
            'Memory' => [
                'used_memory' => '500000',
                'maxmemory' => '0',
            ],
        ]);
        $redis->method('dbsize')->willReturn(4);

        $stats = RedisStatsAsMemcached::general($redis);

        self::assertSame('7.2.1', $stats['version']);
        self::assertSame(100, $stats['pid']);
        self::assertSame(3600, $stats['uptime']);
        self::assertSame(3, $stats['curr_connections']);
        self::assertSame(10, $stats['total_connections']);
        self::assertSame(40, $stats['get_hits']);
        self::assertSame(2, $stats['get_misses']);
        self::assertSame(500000, $stats['bytes']);
        self::assertSame(4, $stats['curr_items']);
        self::assertSame(9, $stats['cmd_set']);
        self::assertSame(5, $stats['cmd_get']);
    }

    public function testItemsReturnsEmptyWhenScanFindsNoKeys(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, []]);

        self::assertSame([], RedisStatsAsMemcached::items($redis, 'pm:v1:*'));
    }

    public function testItemsUsesIdleTimeAndAvgMemoryEstimate(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['pm:v1:item1']]);
        $redis->expects(self::once())->method('object')->with('IDLETIME', 'pm:v1:item1')->willReturn(12);
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '1000'],
        ]);
        $redis->method('dbsize')->willReturn(10);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*');

        self::assertSame(1, $items['items:1:number']);
        self::assertSame(1, $items['items:1:number_cold']);
        self::assertSame(12, $items['items:1:age']);
        self::assertSame(100, $items['items:1:mem_requested']);
    }

    public function testSlabsWithNoKeysOnlyGlobals(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Server' => ['used_memory' => '999'],
        ]);

        $slabs = RedisStatsAsMemcached::slabs($redis, 0);

        self::assertSame(0, $slabs['active_slabs']);
        self::assertSame(999, $slabs['total_malloced']);
        self::assertArrayNotHasKey('1:chunk_size', $slabs);
    }

    public function testSlabsWithKeysIncludesSyntheticClassOne(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Server' => ['used_memory' => '2048'],
            'Commandstats' => [
                'cmdstat_hgetall' => 'calls=2,usec=1',
                'cmdstat_hset' => 'calls=3,usec=1',
            ],
        ]);

        $slabs = RedisStatsAsMemcached::slabs($redis, 2);

        self::assertSame(1, $slabs['active_slabs']);
        self::assertSame(2048, $slabs['total_malloced']);
        self::assertSame(1_048_576, $slabs['1:chunk_size']);
        self::assertSame(2, $slabs['1:used_chunks']);
        self::assertSame(2, $slabs['1:get_hits']);
        self::assertSame(3, $slabs['1:cmd_set']);
    }

    public function testScanCountAndFirstKeyAcrossCursorSteps(): void
    {
        $step = 0;
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturnCallback(static function () use (&$step): array {
            ++$step;

            return 1 === $step ? [42, ['pm:v1:a']] : [0, ['pm:v1:b']];
        });

        $r = RedisStatsAsMemcached::scanCountAndFirstKey($redis, 'pm:v1:*', 100);

        self::assertSame(2, $r['count']);
        self::assertSame('pm:v1:a', $r['firstKey']);
    }
}
