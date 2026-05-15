<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\Internal\RedisInfoReplyFlatten;

final class RedisInfoReplyFlattenTest extends TestCase
{
    public function testFlattensNestedSectionFormat(): void
    {
        $reply = [
            'Server' => [
                'redis_version' => '8.0.0',
                'process_id' => '42',
            ],
            'Memory' => [
                'used_memory' => '12345',
            ],
        ];

        $flat = RedisInfoReplyFlatten::toStringMap($reply);

        self::assertSame([
            'redis_version' => '8.0.0',
            'process_id' => '42',
            'used_memory' => '12345',
        ], $flat);
    }

    public function testPreservesFlatLegacyFormat(): void
    {
        $reply = [
            'redis_version' => '6.2.0',
            'uptime_in_seconds' => '10',
        ];

        self::assertSame([
            'redis_version' => '6.2.0',
            'uptime_in_seconds' => '10',
        ], RedisInfoReplyFlatten::toStringMap($reply));
    }

    public function testSkipsNonStringKeysAndNestedNonScalars(): void
    {
        $reply = [
            'Server' => [
                'ok' => '1',
                'nested' => ['skip' => 'me'],
            ],
            0 => 'ignored',
            'scalar' => true,
        ];

        $flat = RedisInfoReplyFlatten::toStringMap($reply);

        self::assertSame(['ok' => '1'], $flat, 'booleans are omitted so stats parsers do not see "1" for maxmemory');
    }

    public function testLaterSectionOverwritesDuplicateKey(): void
    {
        $reply = [
            'A' => ['x' => '1'],
            'B' => ['x' => '2'],
        ];

        $flat = RedisInfoReplyFlatten::toStringMap($reply);

        self::assertSame('2', $flat['x']);
    }
}
