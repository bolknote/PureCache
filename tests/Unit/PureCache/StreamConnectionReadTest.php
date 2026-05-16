<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\StreamConnection;

final class StreamConnectionReadTest extends TestCase
{
    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function connectedPair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$clientSock, $serverSock] = $pair;
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $clientSock);

        return [$connection, $serverSock];
    }

    public function testReadLineConsumesCrlfTerminatedLine(): void
    {
        [$connection, $server] = $this->connectedPair();
        fwrite($server, "VERSION 1.6.22\r\n");

        self::assertSame('VERSION 1.6.22', $connection->readLine());
        fclose($server);
    }

    public function testReadLineFlexibleAcceptsLfOnly(): void
    {
        [$connection, $server] = $this->connectedPair();
        fwrite($server, "END\n");

        self::assertSame('END', $connection->readLineFlexible());
        fclose($server);
    }

    public function testReadExactPullsDeclaredByteCount(): void
    {
        [$connection, $server] = $this->connectedPair();
        fwrite($server, "hello\r\n");

        self::assertSame('hello', $connection->readExact(5));
        fclose($server);
    }

    public function testReadLineSpansMultipleKernelChunks(): void
    {
        [$connection, $server] = $this->connectedPair();
        fwrite($server, 'HD');
        usleep(10_000);
        fwrite($server, "\r\n");

        self::assertSame('HD', $connection->readLine());
        fclose($server);
    }
}
