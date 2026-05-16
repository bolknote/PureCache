<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class StreamConnectionOptionsTest extends TestCase
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

    public function testConnectAppliesTcpSocketOptionsOnFakeMeta(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $connection = new StreamConnection(
            '127.0.0.1',
            $port,
            2.0,
            500_000,
            500_000,
            'opts-test',
            true,
            true,
            8192,
            8192,
            true,
            1000,
            0,
            0,
            60,
        );
        $connection->bufferWrite("version\r\n");
        self::assertStringContainsString('VERSION', $connection->readLine());
        $connection->close();
    }

    public function testMemcachedClientConnectionStringPortZeroUsesDefaultPort(): void
    {
        $port = 11211;
        $probe = @stream_socket_server('tcp://127.0.0.1:'.$port);
        if (false === $probe) {
            self::markTestSkipped('Cannot bind fake meta worker to port 11211');
        }

        fclose($probe);

        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $client = new \PureCache\Memcached\MemcachedClient(null, null, '127.0.0.1:0');
        self::assertTrue($client->set('port_zero', 'ok'));
        self::assertSame('ok', $client->get('port_zero'));
    }

    public function testPersistentReconnectVerifiesVersionLine(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $first = new StreamConnection('127.0.0.1', $port, 2.0, null, null, 'persist-opts');
        $first->bufferWrite("version\r\n");
        self::assertStringContainsString('VERSION', $first->readLine());

        $second = new StreamConnection('127.0.0.1', $port, 2.0, null, null, 'persist-opts');
        $second->bufferWrite("version\r\n");
        self::assertStringContainsString('VERSION', $second->readLine());
    }

    public function testVerifyPersistentSocketSyncReconnectsWhenPeerClosedBeforeHandshake(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$clientSock, $serverSock] = $pair;
        fclose($serverSock);

        $connection = new StreamConnection('127.0.0.1', $port, 2.0, null, null, 'persist-handshake');
        (new \ReflectionProperty(StreamConnection::class, 'socket'))->setValue($connection, $clientSock);
        (new \ReflectionMethod(StreamConnection::class, 'verifyPersistentSocketSync'))->invoke($connection);

        self::assertTrue($connection->isConnected());
        $connection->close();
    }
}
