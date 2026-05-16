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

    public function testManyReadLinesCompactInternalBuffer(): void
    {
        [$connection, $server] = $this->socketPair();
        for ($i = 0; $i < 500; ++$i) {
            fwrite($server, "line{$i}\r\n");
        }

        for ($i = 0; $i < 500; ++$i) {
            self::assertSame('line'.$i, $connection->readLine());
        }

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
