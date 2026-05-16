<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerSelector;
use PureCache\Memcached\Internal\ConnectionException;
use PureCache\Memcached\Internal\ConnectionManager;
use PureCache\Memcached\Internal\StreamConnection;

final class ConnectionManagerTest extends TestCase
{
    public function testFlushAllBuffersReportsFailuresAndRethrowsFirst(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => '127.0.0.1', 'port' => 9, 'weight' => 1]);

        $manager = new ConnectionManager($selector, 0.05, null, null);
        $connection = $manager->get(0);
        $connection->bufferWrite("version\r\n");

        $seen = [];
        try {
            $manager->flushAllBuffers(static function (int $idx, \Throwable $e) use (&$seen): void {
                $seen[] = [$idx, $e::class];
            });
            self::fail('expected flush to throw');
        } catch (ConnectionException) {
            self::assertSame([[0, ConnectionException::class]], $seen);
        }
    }

    public function testGetRejectsInvalidServerIndex(): void
    {
        $manager = new ConnectionManager(new ServerSelector(), 1.0, null, null);
        $this->expectException(\RuntimeException::class);
        $manager->get(0);
    }

    public function testCloseAllRethrowsFlushFailuresAfterClosingSockets(): void
    {
        $selector = new ServerSelector();
        $selector->addServer(['host' => '127.0.0.1', 'port' => 9, 'weight' => 1]);

        $manager = new ConnectionManager($selector, 0.05, null, null);
        $manager->get(0)->bufferWrite("version\r\n");

        try {
            $manager->closeAll();
            self::fail('expected closeAll to rethrow flush failure');
        } catch (ConnectionException) {
            self::assertSame([], (new \ReflectionProperty(ConnectionManager::class, 'pool'))->getValue($manager));
        }
    }

    public function testResetPoolClosesConnections(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$clientSock, $serverSock] = $pair;

        $selector = new ServerSelector();
        $selector->addServer(['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1]);

        $manager = new ConnectionManager($selector, 1.0, null, null);
        $connection = $manager->get(0);
        (new \ReflectionProperty(StreamConnection::class, 'socket'))->setValue($connection, $clientSock);
        $manager->resetPool();
        self::assertFalse($connection->isConnected());
        fclose($serverSock);
    }
}
