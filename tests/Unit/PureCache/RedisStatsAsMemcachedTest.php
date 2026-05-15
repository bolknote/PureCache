<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\MemcachedStatsSchema;
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

    public function testGeneralUsesPrefixScanCountNotWholeDatabaseSize(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn(['Memory' => ['used_memory' => '0']]);
        $redis->expects(self::never())->method('dbsize');
        $redis->method('scan')->willReturn([0, ['pm:v1:a', 'pm:v1:b']]);

        $stats = RedisStatsAsMemcached::general($redis, 'pm:v1:*');

        self::assertSame(2, $stats['curr_items']);
        self::assertSame(2, $stats['total_items']);
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
        $scan = ['count' => 1, 'firstKey' => 'pm:v1:item1', 'complete' => true];
        $redis->expects(self::once())->method('object')->with('IDLETIME', 'pm:v1:item1')->willReturn(12);
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '1000'],
        ]);
        $redis->method('dbsize')->willReturn(10);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*', $scan);

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

        $slabs = RedisStatsAsMemcached::slabs($redis, ['count' => 0, 'firstKey' => null, 'complete' => true]);

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

        $slabs = RedisStatsAsMemcached::slabs($redis, ['count' => 2, 'firstKey' => 'pm:v1:x', 'complete' => true]);

        self::assertSame(1, $slabs['active_slabs']);
        self::assertSame(2048, $slabs['total_malloced']);
        self::assertSame(MemcachedStatsSchema::SYNTHETIC_SLAB_CHUNK_SIZE, $slabs['1:chunk_size']);
        self::assertSame(2, $slabs['1:used_chunks']);
        self::assertSame(0, $slabs['1:free_chunks']);
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
        self::assertTrue($r['complete']);
    }

    public function testScanCountAndFirstKeyTerminatesAtIterationCap(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([1, ['k']]);

        $r = RedisStatsAsMemcached::scanCountAndFirstKey($redis, '*', 3);

        self::assertSame(3, $r['count']);
        self::assertSame('k', $r['firstKey']);
        self::assertFalse($r['complete']);
    }

    public function testScanCountAndFirstKeySkipsEmptyKeyNames(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['', 'real']]);

        $r = RedisStatsAsMemcached::scanCountAndFirstKey($redis, '*', 100);

        self::assertSame(1, $r['count']);
        self::assertSame('real', $r['firstKey']);
        self::assertTrue($r['complete']);
    }

    public function testItemsUsesDbsizeFastPathForWildcardPattern(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->expects(self::never())->method('scan');
        $redis->method('dbsize')->willReturn(5);
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '5000'],
        ]);

        $items = RedisStatsAsMemcached::items($redis, '*');

        self::assertSame(5, $items['items:1:number']);
        self::assertSame(5, $items['items:1:number_cold']);
        self::assertSame(0, $items['items:1:age'], 'no firstKey → IDLETIME not probed');
        self::assertSame(5000, $items['items:1:mem_requested']);
    }

    public function testItemsUsesFallbackMemoryWhenScanHitsIterationCap(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $scan = ['count' => 4, 'firstKey' => 'k', 'complete' => false];

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*', $scan);

        self::assertSame(4 * MemcachedStatsSchema::PER_KEY_MEMORY_FALLBACK_BYTES, $items['items:1:mem_requested']);
    }

    public function testItemsSurvivesObjectIdletimeFailureWithZeroAge(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $scan = ['count' => 1, 'firstKey' => 'pm:v1:k', 'complete' => true];
        $redis->method('object')->willThrowException(new \RuntimeException('OBJECT not allowed'));
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '1000'],
        ]);
        $redis->method('dbsize')->willReturn(2);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*', $scan);

        self::assertSame(1, $items['items:1:number']);
        self::assertSame(0, $items['items:1:age']);
    }

    public function testItemsIgnoresNonIntIdletimeReply(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $scan = ['count' => 1, 'firstKey' => 'pm:v1:k', 'complete' => true];
        $redis->method('object')->willReturn('12');
        $redis->method('info')->willReturn(['Memory' => ['used_memory' => '0']]);
        $redis->method('dbsize')->willReturn(1);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*', $scan);

        self::assertSame(0, $items['items:1:age']);
    }

    public function testItemsFallsBackToTwoFiftySixBytesPerKeyWhenInfoIsUnavailable(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $scan = ['count' => 2, 'firstKey' => 'k1', 'complete' => true];
        $redis->method('object')->willReturn(0);
        $redis->method('info')->willThrowException(new \RuntimeException('INFO unavailable'));

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*', $scan);

        self::assertSame(2 * MemcachedStatsSchema::PER_KEY_MEMORY_FALLBACK_BYTES, $items['items:1:mem_requested']);
    }

    public function testGeneralCoercesFloatAndBoolInfoValues(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Server' => ['redis_version' => '7.4.0'],
            'Memory' => ['used_memory' => 12345.7, 'maxmemory' => true],
            'Clients' => ['connected_clients' => 'not-a-number'],
        ]);
        $redis->method('dbsize')->willReturn(0);

        $stats = RedisStatsAsMemcached::general($redis);

        self::assertSame('7.4.0', $stats['version']);
        self::assertSame(12345, $stats['bytes']);
        self::assertSame(MemcachedStatsSchema::DEFAULT_MAX_MEMORY, $stats['limit_maxbytes']);
        self::assertSame(0, $stats['curr_connections'], 'unparsable string → default 0');
    }

    public function testGeneralUsesDefaultMaxMemoryWhenRedisHasNoLimit(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '0', 'maxmemory' => '0'],
        ]);
        $redis->method('dbsize')->willReturn(0);

        $stats = RedisStatsAsMemcached::general($redis);

        self::assertSame(MemcachedStatsSchema::DEFAULT_MAX_MEMORY, $stats['limit_maxbytes']);
    }

    public function testGeneralCmdstatLineMissingCallsTokenIsReportedAsZero(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Commandstats' => [
                'cmdstat_get' => 'usec=10,usec_per_call=1',
                'cmdstat_set' => 'calls=4,usec=10',
            ],
        ]);
        $redis->method('dbsize')->willReturn(0);

        $stats = RedisStatsAsMemcached::general($redis);

        self::assertSame(0, $stats['cmd_get'], 'no calls=N token → 0');
        self::assertSame(4, $stats['cmd_set']);
    }
}
