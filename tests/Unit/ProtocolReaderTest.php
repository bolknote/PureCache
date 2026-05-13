<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Internal\MetaReader;
use PureMemcached\Internal\StreamConnection;
use PureMemcached\Internal\TextProtocolClient;

final class ProtocolReaderTest extends TestCase
{
    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketConnection(string $serverData = ''): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$client, $server] = $pair;
        if ('' !== $serverData) {
            fwrite($server, $serverData);
        }

        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $client);

        return [$connection, $server];
    }

    public function testBufferedWritesAreDeferredUntilFlush(): void
    {
        [$connection, $server] = $this->socketConnection();
        stream_set_blocking($server, false);

        $connection->bufferWrite("version\r\n");
        self::assertSame('', stream_get_contents($server));

        $connection->flushWrite();
        self::assertSame("version\r\n", stream_get_contents($server));
        fclose($server);
    }

    public function testReadLineFlushesBufferedWritesBeforeReading(): void
    {
        [$connection, $server] = $this->socketConnection("VERSION 1.6.0\r\n");
        stream_set_blocking($server, false);

        $connection->bufferWrite("version\r\n");
        self::assertSame('VERSION 1.6.0', $connection->readLine());
        self::assertSame("version\r\n", stream_get_contents($server));
        fclose($server);
    }

    public function testMetaReaderReturnsProtocolErrors(): void
    {
        [$connection, $server] = $this->socketConnection("CLIENT_ERROR bad command\r\n");

        $result = (new MetaReader($connection))->readOne(true);

        self::assertSame('CLIENT_ERROR', $result->code);
        self::assertSame('bad command', $result->errorMessage);
        fclose($server);
    }

    public function testMetaReaderReadsEmptyValueBlock(): void
    {
        [$connection, $server] = $this->socketConnection("VA 0 f0\r\n\r\n");

        $result = (new MetaReader($connection))->readOne(true);

        self::assertSame('VA', $result->code);
        self::assertSame('', $result->value);
        fclose($server);
    }

    public function testMetaReaderCanSkipValueBodyWhenNotExpected(): void
    {
        [$connection, $server] = $this->socketConnection("VA 5 f0\r\nvalue\r\n");

        $result = (new MetaReader($connection))->readOne(false);

        self::assertSame('VA', $result->code);
        self::assertNull($result->value);
        fclose($server);
    }

    public function testMetaReaderArithmeticHandlesNonValueResponses(): void
    {
        [$connection, $server] = $this->socketConnection("NF\r\n");

        $result = (new MetaReader($connection))->readArithmeticValue();

        self::assertSame('NF', $result->code);
        self::assertNull($result->value);
        fclose($server);
    }

    public function testMetaReaderArithmeticReadsValueBody(): void
    {
        [$connection, $server] = $this->socketConnection("VA 2\r\n42\r\n");

        $result = (new MetaReader($connection))->readArithmeticValue();

        self::assertSame('VA', $result->code);
        self::assertSame('42', $result->value);
        fclose($server);
    }

    public function testReadExactKeepsExtraBytesForNextLine(): void
    {
        [$connection, $server] = $this->socketConnection("abcdefNEXT\r\n");

        self::assertSame('abc', $connection->readExact(3));
        self::assertSame('defNEXT', $connection->readLine());
        fclose($server);
    }

    public function testTextProtocolVersionRejectsUnexpectedResponse(): void
    {
        [$connection, $server] = $this->socketConnection("NOT_VERSION\r\n");

        self::assertFalse(TextProtocolClient::version($connection));
        fclose($server);
    }

    public function testTextProtocolStatsReturnsFalseOnErrors(): void
    {
        [$connection, $server] = $this->socketConnection("CLIENT_ERROR bad stats\r\n");

        self::assertFalse(TextProtocolClient::stats($connection));
        fclose($server);
    }

    public function testTextProtocolStatsWithTypeReturnsFlatPeclShape(): void
    {
        [$connection, $server] = $this->socketConnection(
            "STAT items:1:number 2\r\n"
            ."STAT items:1:number 3\r\n"
            ."END\r\n",
        );

        self::assertSame(['items:1:number' => 3], TextProtocolClient::stats($connection, 'items'));
        fclose($server);
    }

    public function testTextProtocolVersionReturnsVersionString(): void
    {
        [$connection, $server] = $this->socketConnection("VERSION 1.6.22\r\n");

        self::assertSame('1.6.22', TextProtocolClient::version($connection));
        fclose($server);
    }

    public function testTextProtocolFlushAllWithDelaySendsDelay(): void
    {
        [$connection, $server] = $this->socketConnection("OK\r\n");
        stream_set_blocking($server, false);

        self::assertTrue(TextProtocolClient::flushAll($connection, 5));
        self::assertSame("flush_all 5\r\n", stream_get_contents($server));
        fclose($server);
    }

    public function testTextProtocolFlushAllReturnsFalseOnUnexpectedResponse(): void
    {
        [$connection, $server] = $this->socketConnection("ERROR\r\n");

        self::assertFalse(TextProtocolClient::flushAll($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysReadsCachedumpItems(): void
    {
        [$connection, $server] = $this->socketConnection(
            "STAT items:1:number 1\r\nEND\r\n"
            ."ITEM cached-key [1 b; 0 s]\r\nEND\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysStopsOnCachedumpErrors(): void
    {
        [$connection, $server] = $this->socketConnection(
            "STAT items:1:number 1\r\nEND\r\n"
            ."SERVER_ERROR unavailable\r\n",
        );

        self::assertFalse(TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }
}
