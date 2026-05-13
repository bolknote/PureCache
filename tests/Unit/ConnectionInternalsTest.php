<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Internal\ConnectionException;
use PureMemcached\Internal\ConnectionManager;
use PureMemcached\Internal\ServerSelector;
use PureMemcached\Internal\StreamConnection;

final class ConnectionInternalsTest extends TestCase
{
    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketConnection(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$client, $server] = $pair;
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $client);

        return [$connection, $server];
    }

    public function testStreamConnectionAccessorsAndCloseAreLocal(): void
    {
        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);

        self::assertSame('127.0.0.1', $connection->getHost());
        self::assertSame(11211, $connection->getPort());
        self::assertFalse($connection->isConnected());

        $connection->bufferWrite('noop');
        $connection->close();
        self::assertFalse($connection->isConnected());
    }

    public function testStreamConnectionReportsConnectFailure(): void
    {
        $connection = new StreamConnection('127.0.0.1', 1, 0.01, null, null);

        try {
            $connection->connect();
            self::fail('Expected connect failure');
        } catch (ConnectionException $connectionException) {
            self::assertStringContainsString('Connect failed to 127.0.0.1:1', $connectionException->getMessage());
            self::assertGreaterThan(0, $connectionException->errno());
            self::assertSame($connectionException->errno(), $connectionException->getCode());
        }
    }

    public function testConnectionManagerCachesAndClosesConnections(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1]);

        $manager = new ConnectionManager($selector, 0.1, null, null);

        $first = $manager->get(0);
        $manager->withConnection(0, static function (StreamConnection $connection) use ($first): void {
            TestCase::assertSame($first, $connection);
        });

        self::assertSame($first, $manager->get(0));
        $manager->resetPool();
        self::assertNotSame($first, $manager->get(0));

        $manager->closeAll();
    }

    public function testConnectionManagerRejectsInvalidServerIndex(): void
    {
        $manager = new ConnectionManager(new ServerSelector(), 0.1, null, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid server index');

        $manager->get(0);
    }

    public function testConnectionManagerFlushesBufferedWritesBeforeClose(): void
    {
        [$connection, $server] = $this->socketConnection();
        $connection->bufferWrite("version\r\n");

        $manager = new ConnectionManager(new ServerSelector(), 0.1, null, null);
        $pool = new \ReflectionProperty(ConnectionManager::class, 'pool');
        $pool->setValue($manager, [0 => $connection]);

        $manager->closeAll();
        stream_set_blocking($server, false);
        self::assertSame("version\r\n", stream_get_contents($server));
        fclose($server);
    }

    public function testFlushWriteClosesSocketOnWriteFailure(): void
    {
        [$connection, $server] = $this->socketConnection();
        fclose($server);
        $connection->bufferWrite(str_repeat('x', 1024));

        try {
            $connection->flushWrite();
            self::fail('Expected write failure');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('Write failure to memcached', $runtimeException->getMessage());
            self::assertFalse($connection->isConnected());
        }
    }
}
