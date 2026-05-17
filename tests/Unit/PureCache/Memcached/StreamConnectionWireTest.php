<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\ConnectionException;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class StreamConnectionWireTest extends TestCase
{
    use FakeWireWorkerTrait;

    /** @var list<resource> */
    private array $wireWorkers = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->wireWorkers as $process) {
            $this->stopFakeWireWorker($process);
        }

        $this->wireWorkers = [];
        parent::tearDown();
    }

    public function testReadLineConnectsToFakeMetaAndParsesVersion(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $connection = new StreamConnection('127.0.0.1', $port, 2.0, null, null);
        $connection->bufferWrite("version\r\n");

        $line = $connection->readLine();

        self::assertStringContainsString('VERSION', $line);
        self::assertTrue($connection->isConnected());
        $connection->close();
        self::assertFalse($connection->isConnected());
    }

    public function testReadExactAndConsumeCrLfAfterBodyOnSocketPair(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "0123456789\r\n");

        self::assertSame('0123456789', $connection->readExact(10));
        $connection->consumeCrLfAfterBody();
        fclose($server);
    }

    public function testReadLineFailsWhenPeerCloses(): void
    {
        [$connection, $server] = $this->socketPair();
        fclose($server);

        $this->expectException(ConnectionException::class);
        $connection->readLine();
    }

    public function testIoBytesWatermarkFlushesBufferedWrites(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$clientSock, $serverSock] = $pair;

        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null, null, false, false, 0, 0, false, 1000, 8, 0);
        (new \ReflectionProperty($connection, 'socket'))->setValue($connection, $clientSock);

        $connection->bufferWrite('12345678');
        $payload = stream_get_contents($serverSock, 64);
        self::assertStringContainsString('12345678', (string) $payload);
        fclose($serverSock);
    }

    public function testManyReadLinesCompactInternalBuffer(): void
    {
        // Exercise compactBuffer() via many readLine() calls without socket I/O
        // (socket pairs can time out on slow CI when the kernel buffer stalls).
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$clientSock, $serverSock] = $pair;
        fclose($serverSock);

        (new \ReflectionProperty(StreamConnection::class, 'socket'))->setValue($connection, $clientSock);

        $payload = '';
        for ($i = 0; $i < 500; ++$i) {
            $payload .= "line{$i}\r\n";
        }

        (new \ReflectionProperty($connection, 'readBuffer'))->setValue($connection, $payload);
        (new \ReflectionProperty($connection, 'readOffset'))->setValue($connection, 0);

        for ($i = 0; $i < 500; ++$i) {
            self::assertSame('line'.$i, $connection->readLine());
        }

        fclose($clientSock);
    }

    public function testReadLineFlexibleAcceptsLfOnlyTerminator(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "lf-only\n");
        self::assertSame('lf-only', $connection->readLineFlexible());
        fclose($server);
    }

    public function testReadLineFlexibleStripsCarriageReturnBeforeLf(): void
    {
        [$connection, $server] = $this->socketPair();
        fwrite($server, "cr-stripped\r\n");
        self::assertSame('cr-stripped', $connection->readLineFlexible());
        fclose($server);
    }

    public function testConsumeCrLfAfterBodyRejectsInvalidTerminator(): void
    {
        [$connection, $server] = $this->socketPair();
        fclose($server);
        (new \ReflectionProperty($connection, 'readBuffer'))->setValue($connection, 'xx');
        (new \ReflectionProperty($connection, 'readOffset'))->setValue($connection, 0);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid chunk terminator');
        $connection->consumeCrLfAfterBody();
    }

    public function testConnectViaUnixDomainSocket(): void
    {
        $path = sys_get_temp_dir().'/purecache-'.uniqid('', true).'.sock';
        @unlink($path);
        $server = stream_socket_server('unix://'.$path);
        if (false === $server) {
            self::markTestSkipped('unix socket server unavailable');
        }

        $connection = new StreamConnection($path, 0, 2.0, null, null);
        $peer = stream_socket_accept($server, 5.0);
        if (!\is_resource($peer)) {
            fclose($server);
            self::markTestSkipped('unix socket accept failed');
        }

        fwrite($peer, "VERSION 1.6.6-fake\r\n");
        $connection->bufferWrite("version\r\n");
        self::assertStringContainsString('VERSION', $connection->readLine());
        fclose($peer);
        fclose($server);
        @unlink($path);
    }

    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$clientSock, $serverSock] = $pair;
        stream_set_timeout($clientSock, 30);
        stream_set_timeout($serverSock, 30);
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $clientSock);

        return [$connection, $serverSock];
    }
}
