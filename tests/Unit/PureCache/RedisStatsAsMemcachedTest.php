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

    public function testScanCountAndFirstKeyTerminatesAtIterationCap(): void
    {
        // Redis SCAN cursors never reach 0 here — without the iteration cap
        // the helper would loop forever. The cap exists exactly to protect
        // observability calls from runaway DBs.
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([1, ['k']]);

        $r = RedisStatsAsMemcached::scanCountAndFirstKey($redis, '*', 3);

        self::assertSame(3, $r['count']);
        self::assertSame('k', $r['firstKey']);
    }

    public function testScanCountAndFirstKeySkipsEmptyKeyNames(): void
    {
        // Some Redis builds (and Dragonfly) occasionally return empty strings
        // for keys that were deleted mid-scan; we must not count them, and we
        // must not pick one as the "first" key for the OBJECT IDLETIME probe.
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['', 'real']]);

        $r = RedisStatsAsMemcached::scanCountAndFirstKey($redis, '*', 100);

        self::assertSame(1, $r['count']);
        self::assertSame('real', $r['firstKey']);
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
        // dbsize == count so per-key average is exactly 1000.
        self::assertSame(5000, $items['items:1:mem_requested']);
    }

    public function testItemsSurvivesObjectIdletimeFailureWithZeroAge(): void
    {
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['pm:v1:k']]);
        $redis->method('object')->willThrowException(new \RuntimeException('OBJECT not allowed'));
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '1000'],
        ]);
        $redis->method('dbsize')->willReturn(2);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*');

        self::assertSame(1, $items['items:1:number']);
        self::assertSame(0, $items['items:1:age']);
    }

    public function testItemsIgnoresNonIntIdletimeReply(): void
    {
        // Some redis-cli compatibility layers return OBJECT IDLETIME as a
        // string when the resp parser doesn't auto-coerce numerics. The age
        // field must stay 0 instead of e.g. casting "12" → 12 unchecked —
        // memcached's stats schema is integer-only.
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['pm:v1:k']]);
        $redis->method('object')->willReturn('12');
        $redis->method('info')->willReturn(['Memory' => ['used_memory' => '0']]);
        $redis->method('dbsize')->willReturn(1);

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*');

        self::assertSame(0, $items['items:1:age']);
    }

    public function testItemsFallsBackToTwoFiftySixBytesPerKeyWhenInfoIsUnavailable(): void
    {
        // estimateMemRequestedBytes() swallows backend errors so a flaky
        // server doesn't poison the entire stats RPC. The fallback constant
        // matches the conservative per-item estimate documented in the file.
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('scan')->willReturn([0, ['k1', 'k2']]);
        $redis->method('object')->willReturn(0);
        $redis->method('info')->willThrowException(new \RuntimeException('INFO unavailable'));

        $items = RedisStatsAsMemcached::items($redis, 'pm:v1:*');

        self::assertSame(2 * 256, $items['items:1:mem_requested']);
    }

    public function testGeneralCoercesFloatAndBoolInfoValues(): void
    {
        // INFO numeric fields arrive as strings over RESP, but redis client
        // libraries differ in how they pre-coerce them. intFrom() must accept
        // floats (truncate) and bools (cast) without losing track of zero.
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
        self::assertSame(1, $stats['limit_maxbytes']);
        self::assertSame(0, $stats['curr_connections'], 'unparsable string → default 0');
    }

    public function testGeneralUsesDefaultMaxMemoryWhenRedisHasNoLimit(): void
    {
        // Redis omits/reports maxmemory=0 when no `maxmemory` directive is
        // configured (default for new deployments). memcached's stats expose
        // limit_maxbytes — we surface 8 GB so dashboards have something to
        // chart instead of the misleading 0.
        $redis = $this->createMock(RedisStatsBackend::class);
        $redis->method('info')->willReturn([
            'Memory' => ['used_memory' => '0', 'maxmemory' => '0'],
        ]);
        $redis->method('dbsize')->willReturn(0);

        $stats = RedisStatsAsMemcached::general($redis);

        self::assertSame(8_589_934_592, $stats['limit_maxbytes']);
    }

    public function testGeneralCmdstatLineMissingCallsTokenIsReportedAsZero(): void
    {
        // Real-world INFO commandstats lines always carry `calls=N`, but if a
        // Redis fork ever ships them with only the latency histogram, the
        // counter regex must fail closed (return 0) instead of throwing.
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
