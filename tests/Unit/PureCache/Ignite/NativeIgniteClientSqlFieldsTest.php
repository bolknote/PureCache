<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteWire;
use PureCache\Ignite\NativeIgniteClient;

final class NativeIgniteClientSqlFieldsTest extends TestCase
{
    public function testParseSqlFieldsVersionReadsFirstRowFirstColumn(): void
    {
        $response = IgniteWire::packInt64(42)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject('2.16.0')
            .IgniteWire::packInt8(0);

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsVersion');

        $client = new NativeIgniteClient('127.0.0.1', 10800);
        self::assertSame('2.16.0', $method->invoke($client, $response));
    }

    public function testParseSqlFieldsVersionRejectsEmptyFirstCell(): void
    {
        $response = IgniteWire::packInt64(0)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeNullObject()
            .IgniteWire::packInt8(0);

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsVersion');

        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty VERSION');

        $method->invoke($client, $response);
    }

    public function testParseSqlFieldsVersionRejectsTrailingBytesAfterHasMore(): void
    {
        $response = IgniteWire::packInt64(0)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject('2.16.0')
            .IgniteWire::packInt8(0)
            ."\x00";

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsVersion');

        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trailing bytes');

        $method->invoke($client, $response);
    }

    public function testParseSqlFieldsVersionIgnoresExtraRows(): void
    {
        $response = IgniteWire::packInt64(0)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(2)
            .IgniteCacheCodec::encodeStringObject('2.16.0')
            .IgniteCacheCodec::encodeStringObject('9.9.9-should-not-win')
            .IgniteWire::packInt8(0);

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseSqlFieldsVersion');

        $client = new NativeIgniteClient('127.0.0.1', 10800);
        self::assertSame('2.16.0', $method->invoke($client, $response));
    }
}
