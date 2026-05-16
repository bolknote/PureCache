<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\Internal\RedisInfoReplyFlatten;

final class RedisInfoReplyFlattenTest extends TestCase
{
    public function testToStringMapFlattensNestedSectionsAndSkipsBools(): void
    {
        $flat = RedisInfoReplyFlatten::toStringMap([
            'uptime_in_seconds' => 42,
            'cluster_enabled' => false,
            '# Server' => [
                'redis_version' => '7.2.0',
                'loading' => true,
                99 => 'ignored',
            ],
            123 => 'ignored-key',
        ]);

        self::assertSame([
            'uptime_in_seconds' => '42',
            'redis_version' => '7.2.0',
        ], $flat);
    }

    public function testToStringMapCoercesScalarsAndNull(): void
    {
        $flat = RedisInfoReplyFlatten::toStringMap([
            'connected_clients' => 3,
            'used_memory' => null,
        ]);

        self::assertSame([
            'connected_clients' => '3',
            'used_memory' => '',
        ], $flat);
    }
}
