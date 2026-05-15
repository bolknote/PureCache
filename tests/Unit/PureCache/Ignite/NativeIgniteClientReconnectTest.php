<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\NativeIgniteClient;

final class NativeIgniteClientReconnectTest extends TestCase
{
    public function testCloseStreamPreservesCountersUntilDisconnect(): void
    {
        $client = new NativeIgniteClient('127.0.0.1', 10800);

        $opCounts = new \ReflectionProperty(NativeIgniteClient::class, 'opCounts');
        $opCounts->setValue($client, [IgniteProtocol::OP_CACHE_GET => 3]);

        $bytesRead = new \ReflectionProperty(NativeIgniteClient::class, 'bytesRead');
        $bytesRead->setValue($client, 128);

        $bytesWritten = new \ReflectionProperty(NativeIgniteClient::class, 'bytesWritten');
        $bytesWritten->setValue($client, 64);

        $closeStream = new \ReflectionMethod(NativeIgniteClient::class, 'closeStream');
        $closeStream->invoke($client);

        self::assertSame([IgniteProtocol::OP_CACHE_GET => 3], $opCounts->getValue($client));
        self::assertSame(128, $bytesRead->getValue($client));
        self::assertSame(64, $bytesWritten->getValue($client));

        $client->disconnect();

        self::assertSame([], $opCounts->getValue($client));
        self::assertSame(0, $bytesRead->getValue($client));
        self::assertSame(0, $bytesWritten->getValue($client));
    }
}
