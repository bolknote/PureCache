<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;

final class NativeRedisClientFailureTest extends TestCase
{
    public function testConnectToClosedPortThrows(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 9, 0.05);

        $this->expectException(\RuntimeException::class);
        $client->connect();
    }

    public function testExecuteRawOnInjectedClosedStreamFails(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$reader, $writer] = $pair;
        fclose($writer);
        (new \ReflectionProperty($client, 'stream'))->setValue($client, $reader);

        $this->expectException(\RuntimeException::class);
        $client->executeRaw(['PING']);
    }
}
