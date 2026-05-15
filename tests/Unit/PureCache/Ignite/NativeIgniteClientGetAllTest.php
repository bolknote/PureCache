<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteWire;
use PureCache\Ignite\NativeIgniteClient;

final class NativeIgniteClientGetAllTest extends TestCase
{
    public function testParseGetAllResponseRejectsCountAboveRequestedKeys(): void
    {
        $response = IgniteWire::packInt32(2);

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseGetAllResponse');

        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds requested');

        $method->invoke($client, $response, ['only-key']);
    }

    public function testParseGetAllResponseRejectsTrailingBytes(): void
    {
        $response = IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject('k')
            .IgniteCacheCodec::encodeByteArrayObject('v')
            ."\x00";

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseGetAllResponse');

        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trailing bytes');

        $method->invoke($client, $response, ['k']);
    }

    public function testParseGetAllResponseRejectsUnexpectedKey(): void
    {
        $response = IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject('not-requested')
            .IgniteCacheCodec::encodeByteArrayObject('v');

        $method = new \ReflectionMethod(NativeIgniteClient::class, 'parseGetAllResponse');

        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected key');

        $method->invoke($client, $response, ['expected-key']);
    }
}
