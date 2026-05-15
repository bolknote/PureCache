<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteWire;
use PureCache\Ignite\NativeIgniteClient;

final class NativeIgniteClientSqlFieldsTest extends TestCase
{
    public function testParseSqlFieldsFirstCellReadsFirstNonEmptyStringColumn(): void
    {
        $response = IgniteWire::packInt64(42)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject('2.16.0');

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsFirstCell');

        $client = new NativeIgniteClient('127.0.0.1', 10800);
        self::assertSame('2.16.0', $method->invoke($client, $response));
    }

    public function testParseSqlFieldsFirstCellSkipsLeadingEmptyCells(): void
    {
        $response = IgniteWire::packInt64(1)
            .IgniteWire::packInt32(2)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeNullObject()
            .IgniteCacheCodec::encodeStringObject('8.9.20');

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsFirstCell');

        $client = new NativeIgniteClient('127.0.0.1', 10800);
        self::assertSame('8.9.20', $method->invoke($client, $response));
    }
}
