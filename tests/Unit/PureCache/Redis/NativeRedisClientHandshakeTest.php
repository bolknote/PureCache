<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Redis;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;
use PureCache\Redis\RedisCommandException;

final class NativeRedisClientHandshakeTest extends TestCase
{
    public function testAuthRejectionClosesConnection(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0, 0.0, null, 'secret');
        $writer = $this->attachReadSide($client);

        fwrite($writer, "-ERR invalid password\r\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('AUTH rejected');
            $this->invokeHandshake($client);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testSelectRejectionClosesConnection(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0, 0.0, null, null, 9);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "-ERR DB out of range\r\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SELECT 9 rejected');
            $this->invokeHandshake($client);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testAuthWithUsernameCompletesHandshake(): void
    {
        self::expectNotToPerformAssertions();

        $client = new NativeRedisClient('127.0.0.1', 0, 0.0, 'default', 'secret');
        $writer = $this->attachReadSide($client);

        fwrite($writer, "+OK\r\n");

        try {
            $this->invokeHandshake($client);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testEvalScriptReloadsOnNoscript(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "-NOSCRIPT No matching script\r\n+OK\r\n:1\r\n");

        try {
            $result = $client->evalScript('return 1', ['k'], []);
            self::assertSame(1, $result);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testHgetallSkipsNonStringPairs(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "*4\r\n\$1\r\nk\r\n:1\r\n\$1\r\nv\r\n\$2\r\nok\r\n");

        try {
            $hash = $client->hgetall('key');
            self::assertSame(['v' => 'ok'], $hash);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testScanWithMatchAndCount(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "*2\r\n:0\r\n*1\r\n\$3\r\nfoo\r\n");

        try {
            [$cursor, $keys] = $client->scan(0, ['MATCH' => 'f*', 'COUNT' => 10]);
            self::assertSame(0, $cursor);
            self::assertSame(['foo'], $keys);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testScanRejectsInvalidCount(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $this->attachReadSide($client);

        $this->expectException(\InvalidArgumentException::class);
        $client->scan(0, ['COUNT' => 0]);
    }

    public function testInfoParsesSectionedResponse(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        $body = "# Server\r\nredis_version:7.0.0\r\n";
        fwrite($writer, '$'.\strlen($body)."\r\n".$body."\r\n");

        try {
            $info = $client->info();
            $server = $info['Server'] ?? null;
            self::assertIsArray($server);
            self::assertSame('7.0.0', $server['redis_version'] ?? null);
        } finally {
            $this->detachStream($client, $writer);
        }
    }

    public function testPipelineCapturesThrowablePerCommand(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $writer = $this->attachReadSide($client);

        fwrite($writer, "+OK\r\n-ERR boom\r\n");

        try {
            $replies = $client->pipeline([['PING'], ['BAD']]);
            self::assertSame('OK', $replies[0]);
            self::assertInstanceOf(RedisCommandException::class, $replies[1]);
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

    private function invokeHandshake(NativeRedisClient $client): void
    {
        $method = new \ReflectionMethod($client, 'performHandshake');
        $method->invoke($client);
    }
}
