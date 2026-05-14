<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteStatsAsMemcached;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteStatsSnapshot;

final class IgniteStatsAsMemcachedTest extends TestCase
{
    public function testGeneralEmitsMemcachedSkeletonAndPropagatesCounters(): void
    {
        $snapshot = new IgniteStatsSnapshot(
            serverVersion: '1.2.0',
            connectedAt: time() - 90,
            bytesRead: 4_096,
            bytesWritten: 1_024,
            opCounts: [
                IgniteProtocol::OP_CACHE_GET => 7,
                IgniteProtocol::OP_CACHE_PUT => 3,
                IgniteProtocol::OP_CACHE_PUT_IF_ABSENT => 1,
                IgniteProtocol::OP_CACHE_REPLACE => 2,
                IgniteProtocol::OP_CACHE_REPLACE_IF_EQUALS => 4,
                IgniteProtocol::OP_CACHE_CONTAINS_KEY => 5,
                IgniteProtocol::OP_CACHE_CLEAR => 1,
                IgniteProtocol::OP_CACHE_REMOVE_KEY => 6,
            ],
        );

        $stats = IgniteStatsAsMemcached::general($snapshot, currItems: 12);

        self::assertSame('1.2.0', $stats['version']);
        self::assertSame('ignite-thin-client', $stats['libevent']);
        self::assertSame(0, $stats['pid']);
        self::assertSame(64, $stats['pointer_size']);
        self::assertSame(12, $stats['curr_items']);
        self::assertSame(12, $stats['total_items']);
        self::assertSame(1, $stats['curr_connections']);
        self::assertSame(1, $stats['total_connections']);
        self::assertSame(1, $stats['accepting_conns']);
        self::assertSame(1, $stats['threads']);

        self::assertSame(7, $stats['cmd_get']);
        self::assertSame(3 + 1 + 2 + 4, $stats['cmd_set']);
        self::assertSame(5, $stats['cmd_touch']);
        self::assertSame(1, $stats['cmd_flush']);
        self::assertSame(6, $stats['delete_hits']);

        self::assertSame(4_096, $stats['bytes_read']);
        self::assertSame(1_024, $stats['bytes_written']);

        self::assertIsInt($stats['uptime']);
        self::assertGreaterThanOrEqual(90, $stats['uptime']);

        foreach (['get_hits', 'get_misses', 'incr_hits', 'decr_hits', 'cas_hits', 'cas_badval', 'auth_cmds'] as $zero) {
            self::assertSame(0, $stats[$zero], 'expected '.$zero.' to default to 0');
        }

        self::assertArrayHasKey('limit_maxbytes', $stats);
        self::assertGreaterThan(0, $stats['limit_maxbytes']);
    }

    public function testGeneralReportsZeroUptimeBeforeHandshake(): void
    {
        $snapshot = new IgniteStatsSnapshot('', 0, 0, 0, []);

        $stats = IgniteStatsAsMemcached::general($snapshot, currItems: 0);

        self::assertSame('unknown', $stats['version']);
        self::assertSame(0, $stats['uptime']);
        self::assertSame(0, $stats['curr_items']);
    }

    public function testItemsIsEmptyWhenCacheHasNoEntries(): void
    {
        self::assertSame([], IgniteStatsAsMemcached::items(0));
    }

    public function testItemsExposesSlab1AndAllMemcachedSuffixes(): void
    {
        $items = IgniteStatsAsMemcached::items(4);

        self::assertSame(4, $items['items:1:number']);
        self::assertSame(4, $items['items:1:number_cold']);
        self::assertSame(0, $items['items:1:age']);
        self::assertSame(0, $items['items:1:mem_requested']);

        foreach (['evicted', 'reclaimed', 'crawler_reclaimed', 'hits_to_hot'] as $suffix) {
            self::assertArrayHasKey('items:1:'.$suffix, $items, 'items skeleton must include items:1:'.$suffix);
            self::assertSame(0, $items['items:1:'.$suffix]);
        }
    }

    public function testSlabsExposesSingleSlabSkeletonAndPropagatesOpCounts(): void
    {
        $snapshot = new IgniteStatsSnapshot(
            serverVersion: '1.2.0',
            connectedAt: time(),
            bytesRead: 0,
            bytesWritten: 0,
            opCounts: [
                IgniteProtocol::OP_CACHE_GET => 2,
                IgniteProtocol::OP_CACHE_PUT => 5,
                IgniteProtocol::OP_CACHE_REMOVE_KEY => 1,
                IgniteProtocol::OP_CACHE_CONTAINS_KEY => 3,
            ],
        );

        $slabs = IgniteStatsAsMemcached::slabs($snapshot, 8);

        self::assertSame(1, $slabs['active_slabs']);
        self::assertSame(0, $slabs['total_malloced']);
        self::assertSame(16_384, $slabs['1:chunk_size']);
        self::assertSame(8, $slabs['1:used_chunks']);
        self::assertSame(8, $slabs['1:total_chunks']);
        self::assertSame(1, $slabs['1:total_pages']);
        self::assertSame(2, $slabs['1:get_hits']);
        self::assertSame(5, $slabs['1:cmd_set']);
        self::assertSame(1, $slabs['1:delete_hits']);
        self::assertSame(3, $slabs['1:touch_hits']);
    }

    public function testSlabsReturnsHeaderOnlyWhenEmpty(): void
    {
        $snapshot = new IgniteStatsSnapshot('', 0, 0, 0, []);

        self::assertSame(
            ['active_slabs' => 0, 'total_malloced' => 0],
            IgniteStatsAsMemcached::slabs($snapshot, 0),
        );
    }

    public function testSizesIsAlwaysReportedAsDisabled(): void
    {
        self::assertSame(['sizes_status' => 'disabled'], IgniteStatsAsMemcached::sizes());
    }

    public function testSnapshotSumsOnlyKnownOpcodes(): void
    {
        $snapshot = new IgniteStatsSnapshot(
            serverVersion: '',
            connectedAt: 0,
            bytesRead: 0,
            bytesWritten: 0,
            opCounts: [
                IgniteProtocol::OP_CACHE_GET => 3,
                IgniteProtocol::OP_CACHE_PUT => 4,
            ],
        );

        self::assertSame(3, $snapshot->sumOpCounts([IgniteProtocol::OP_CACHE_GET]));
        self::assertSame(7, $snapshot->totalOps());
        self::assertSame(0, $snapshot->sumOpCounts([IgniteProtocol::OP_CACHE_CLEAR]));
    }
}
