<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;

final class NativeRedisClientPipelineTest extends TestCase
{
    public function testPipelineDrainsMultipleInlineReplies(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "+OK\r\n+QUEUED\r\n");

        try {
            $replies = $client->pipeline([
                ['PING'],
                ['ECHO', 'hi'],
            ]);
            self::assertCount(2, $replies);
            self::assertSame('OK', $replies[0]);
            self::assertSame('QUEUED', $replies[1]);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    /**
     * @return resource
     */
    private function attachReadSide(NativeRedisClient $client): mixed
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (false === $pair) {
            self::fail('stream_socket_pair() failed');
        }

        [$reader, $writer] = $pair;
        (new \ReflectionProperty($client, 'stream'))->setValue($client, $reader);

        return $writer;
    }

    /**
     * @param resource $writer
     */
    private function detachStream(NativeRedisClient $client, $writer): void
    {
        $property = new \ReflectionProperty($client, 'stream');
        $reader = $property->getValue($client);
        $property->setValue($client, null);
        if (\is_resource($reader)) {
            @fclose($reader);
        }

        @fclose($writer);
    }
}
