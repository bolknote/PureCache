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

    public function testVersionReturnsRawVersionLine(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22-fake\r\n");

        self::assertSame('1.6.22-fake', TextProtocolClient::version($connection));
        fclose($server);
    }

    public function testGetAllKeysUsesMetadumpForModernVersion(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22\r\n");
        fwrite($server, "OK\r\n");
        fwrite($server, "key=foo%20bar\r\n");
        fwrite($server, "END\r\n");

        $keys = TextProtocolClient::getAllKeys($connection);
        self::assertSame(['foo bar'], $keys);
        fclose($server);
    }

    public function testGetAllKeysFallsBackToCachedumpWhenMetadumpBusy(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22\r\n");
        fwrite($server, "BUSY\r\n");
        fwrite($server, "STAT items:1:number 1\r\n");
        fwrite($server, "END\r\n");
        fwrite($server, "ITEM cached-key [0 b; 0 s]\r\n");
        fwrite($server, "END\r\n");

        $keys = TextProtocolClient::getAllKeys($connection);
        self::assertSame(['cached-key'], $keys);
        fclose($server);
    }

    public function testGetAllKeysViaCachedumpForLegacyVersion(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.4\r\n");
        fwrite($server, "STAT items:2:number 1\r\n");
        fwrite($server, "END\r\n");
        fwrite($server, "ITEM legacy-key [0 b; 0 s]\r\n");
        fwrite($server, "END\r\n");

        $keys = TextProtocolClient::getAllKeys($connection);
        self::assertSame(['legacy-key'], $keys);
        fclose($server);
    }

    public function testGetAllKeysReturnsEmptyWhenMetadumpReportsNotStarted(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22\r\n");
        fwrite($server, "NOTSTARTED\r\n");

        self::assertSame([], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testGetAllKeysReturnsEmptyWhenMetadumpEndsImmediately(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22\r\n");
        fwrite($server, "END\r\n");

        self::assertSame([], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testGetAllKeysFailsWhenMetadumpLineIsUnparseable(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.6.22\r\n");
        fwrite($server, "not-a-metadump-key-line\r\n");
        fwrite($server, "END\r\n");

        self::assertFalse(TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testGetAllKeysFailsWhenCachedumpStatsReturnError(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.4\r\n");
        fwrite($server, "ERROR\r\n");

        self::assertFalse(TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testGetAllKeysNormalizesTwoPartVersionStrings(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "VERSION 1.5-beta\r\n");
        fwrite($server, "END\r\n");

        self::assertSame([], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testFlushAllWithDelaySendsDelayedCommand(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "OK\r\n");

        self::assertTrue(TextProtocolClient::flushAll($connection, 5));
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
