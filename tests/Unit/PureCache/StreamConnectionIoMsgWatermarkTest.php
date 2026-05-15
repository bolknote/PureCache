<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\StreamConnection;

final class StreamConnectionIoMsgWatermarkTest extends TestCase
{
    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        self::assertCount(2, $pair);

        return [$pair[0], $pair[1]];
    }

    public function testMessageWatermarkFlushesWhenByteWatermarkWouldNot(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        stream_set_blocking($serverSock, false);

        $conn = new StreamConnection(
            '127.0.0.1',
            11211,
            0.1,
            null,
            null,
            null,
            false,
            false,
            0,
            0,
            false,
            1000,
            1_000_000,
            2,
            0,
        );

        $socketProp = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socketProp->setValue($conn, $clientSock);

        $conn->bufferWrite("one\r\n");
        self::assertSame('', (string) fread($serverSock, 8192));

        $conn->bufferWrite("two\r\n");
        stream_set_blocking($serverSock, true);
        $data = (string) fread($serverSock, 8192);
        self::assertStringContainsString("one\r\n", $data);
        self::assertStringContainsString("two\r\n", $data);
    }

    public function testMessageCountResetsAfterSuccessfulFlush(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();

        $conn = new StreamConnection(
            '127.0.0.1',
            11211,
            0.1,
            null,
            null,
            null,
            false,
            false,
            0,
            0,
            false,
            1000,
            1_000_000,
            2,
            0,
        );

        $socketProp = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socketProp->setValue($conn, $clientSock);

        $conn->bufferWrite("a\r\n");
        $conn->bufferWrite("b\r\n");
        fread($serverSock, 8192);

        $conn->bufferWrite("c\r\n");
        $countProp = new \ReflectionProperty(StreamConnection::class, 'bufferedMessageCount');
        self::assertSame(1, $countProp->getValue($conn));

        $conn->bufferWrite("d\r\n");
        fread($serverSock, 8192);
        self::assertSame(0, $countProp->getValue($conn));
    }
}
