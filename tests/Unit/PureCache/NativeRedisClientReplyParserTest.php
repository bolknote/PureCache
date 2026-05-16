<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\NativeRedisClient;

/**
 * Exercises the RESP2 parser by feeding bytes through a socket pair so we can
 * cover failure modes without spinning up Redis. The parser is private; we use
 * reflection to inject the read side and to invoke {@code readReply()}.
 */
final class NativeRedisClientReplyParserTest extends TestCase
{
    public function testArrayLengthIsCappedToPreventStackBlowup(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        // RESP "*N\r\n" where N >> MAX_ARRAY_REPLY_LENGTH. The client must
        // refuse to recurse rather than allocating a billion-slot reply.
        $count = NativeRedisClient::MAX_ARRAY_REPLY_LENGTH + 1;
        fwrite($stream, '*'.$count."\r\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('RESP array reply exceeds safety limit');
            $this->invokeReadReply($client);
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testInlineBulkStringRoundTripsThroughParser(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "\$5\r\nhello\r\n");

        try {
            self::assertSame('hello', $this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testNullBulkStringIsReturnedAsNull(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "\$-1\r\n");

        try {
            self::assertNull($this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testBulkStringLengthIsCapped(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0, 0.0, null, null, null, 8);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "\$16\r\n");

        try {
            $this->expectException(\PureCache\Redis\RedisCommandException::class);
            $this->expectExceptionMessage('item size limit');
            $this->invokeReadReply($client);
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testRedisErrorReplyIsTranslatedToCommandException(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "-NOSCRIPT No matching script\r\n");

        try {
            $this->expectException(\PureCache\Redis\RedisCommandException::class);
            $this->expectExceptionMessage('NOSCRIPT');
            $this->invokeReadReply($client);
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testIntegerReplyIsParsed(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, ":42\r\n");

        try {
            self::assertSame(42, $this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testSimpleStringReplyIsParsed(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "+OK\r\n");

        try {
            self::assertSame('OK', $this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testNullAggregateReplyIsParsed(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "_\r\n");

        try {
            self::assertNull($this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testUnsupportedRespTypeThrows(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, '?');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unsupported RESP type');
            $this->invokeReadReply($client);
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testEmptyArrayReplyIsParsed(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "*0\r\n");

        try {
            self::assertSame([], $this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    public function testNestedArrayReplyIsParsed(): void
    {
        $client = new NativeRedisClient('127.0.0.1', 0);
        $stream = $this->attachReadSide($client);

        fwrite($stream, "*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");

        try {
            self::assertSame(['foo', 'bar'], $this->invokeReadReply($client));
        } finally {
            $this->detachStream($client, $stream);
        }
    }

    /**
     * Returns the writer half of a socket pair; the reader half is wired to
     * the client's private {@code $stream} via reflection.
     *
     * @return resource
     */
    private function attachReadSide(NativeRedisClient $client): mixed
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (false === $pair) {
            self::fail('stream_socket_pair() failed');
        }

        [$reader, $writer] = $pair;

        $property = new \ReflectionProperty($client, 'stream');
        $property->setValue($client, $reader);

        return $writer;
    }

    /**
     * Reset the client's stream property to {@code null} and close our fake
     * socket pair. Otherwise {@see NativeRedisClient::__destruct()} would try
     * to send a {@code QUIT} command and block waiting for the reply, since
     * our reader side has nothing to answer back.
     *
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

    private function invokeReadReply(NativeRedisClient $client): mixed
    {
        $method = new \ReflectionMethod($client, 'readReply');

        return $method->invoke($client);
    }
}
