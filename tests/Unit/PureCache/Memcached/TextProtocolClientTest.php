<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Memcached\Internal\TextProtocolClient;

final class TextProtocolClientTest extends TestCase
{
    public function testStatsParsesIntegerAndFloatValues(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "STAT pid 42\r\n");
        fwrite($server, "STAT ratio 1.25\r\n");
        fwrite($server, "STAT name raw\r\n");
        fwrite($server, "END\r\n");

        $stats = TextProtocolClient::stats($connection);
        self::assertIsArray($stats);
        self::assertSame(42, $stats['pid']);
        self::assertSame(1.25, $stats['ratio']);
        self::assertSame('raw', $stats['name']);

        fclose($server);
    }

    public function testStatsReturnsFalseOnErrorLine(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "ERROR\r\n");

        self::assertFalse(TextProtocolClient::stats($connection, 'items'));
        fclose($server);
    }

    public function testFlushAllReturnsFalseOnErrorLine(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "ERROR\r\n");

        self::assertFalse(TextProtocolClient::flushAll($connection));
        fclose($server);
    }

    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$clientSock, $serverSock] = $pair;
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $clientSock);

        return [$connection, $serverSock];
    }
}
