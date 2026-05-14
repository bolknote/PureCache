<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\MetaReader;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Memcached\Internal\TextProtocolClient;

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

    /**
     * Even when the caller passes {@code expectValueBlock=false}, we still
     * have to consume the {@code VA} body + CRLF from the socket — otherwise
     * the next read would pick up the orphaned body bytes and corrupt the
     * stream. Regression: previously the body was left in the buffer.
     */
    public function testMetaReaderConsumesUnwantedValueBodyToKeepSocketInSync(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VA 5 f0\r\nvalue\r\nHD\r\n",
        );

        $reader = new MetaReader($connection);

        $first = $reader->readOne(false);
        self::assertSame('VA', $first->code);
        self::assertNull($first->value);

        $second = $reader->readOne(false);
        self::assertSame('HD', $second->code);

        fclose($server);
    }

    public function testMetaReaderConsumesUnwantedEmptyValueBodyToKeepSocketInSync(): void
    {
        [$connection, $server] = $this->socketConnection("VA 0 f0\r\n\r\nHD\r\n");

        $reader = new MetaReader($connection);
        $first = $reader->readOne(false);
        self::assertSame('VA', $first->code);
        self::assertNull($first->value);

        self::assertSame('HD', $reader->readOne(false)->code);

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
            "VERSION 1.4.35\r\n"
            ."STAT items:1:number 1\r\nEND\r\n"
            ."ITEM cached-key [1 b; 0 s]\r\nEND\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysStopsOnCachedumpErrors(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.4.35\r\n"
            ."STAT items:1:number 1\r\nEND\r\n"
            ."SERVER_ERROR unavailable\r\n",
        );

        self::assertFalse(TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysSkipsMetadumpBelow156(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.5.5\r\n"
            ."STAT items:1:number 1\r\nEND\r\n"
            ."ITEM cached-key [1 b; 0 s]\r\nEND\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysUsesMetadumpOnNewMemcached(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.6.0\r\n"
            ."key=cached-key exp=-1 la=1 cas=1 fetch=no cls=1 size=80\n"
            ."END\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysMetadumpDecodesUriEncodedKey(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.6.0\r\n"
            ."key=my%20key exp=-1 la=1 cas=1 fetch=no cls=1 size=80\n"
            ."END\r\n",
        );

        self::assertSame(['my key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysMetadumpNotstartedReturnsEmpty(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.6.0\r\n"
            ."NOTSTARTED no items to crawl\r\n",
        );

        self::assertSame([], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysMetadumpBusyFallsBackToCachedump(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.6.0\r\n"
            ."BUSY currently processing crawler request\r\n"
            ."STAT items:1:number 1\r\nEND\r\n"
            ."ITEM cached-key [1 b; 0 s]\r\nEND\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testTextProtocolGetAllKeysMetadumpNotAllowedFallsBackToCachedump(): void
    {
        [$connection, $server] = $this->socketConnection(
            "VERSION 1.6.0\r\n"
            ."ERROR metadump not allowed\r\n"
            ."STAT items:1:number 1\r\nEND\r\n"
            ."ITEM cached-key [1 b; 0 s]\r\nEND\r\n",
        );

        self::assertSame(['cached-key'], TextProtocolClient::getAllKeys($connection));
        fclose($server);
    }

    public function testReadLineFlexibleAcceptsLfAndCrlf(): void
    {
        [$connection, $server] = $this->socketConnection("alpha\nbeta\r\n");
        self::assertSame('alpha', $connection->readLineFlexible());
        self::assertSame('beta', $connection->readLineFlexible());
        fclose($server);
    }
}
