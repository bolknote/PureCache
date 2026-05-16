<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\MetaReader;
use PureCache\Memcached\Internal\MetaValueReader;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\MemcachedConstants;

final class MetaReaderSizeLimitTest extends TestCase
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

    public function testOversizedVaReturnsE2BigAndConsumesBody(): void
    {
        $payload = str_repeat('x', 20);
        $wire = 'VA 20 f0'."\r\n".$payload."\r\n".'HD t1'."\r\n";
        [$connection, $server] = $this->socketConnection($wire);

        $reader = new MetaReader($connection, 10);
        $decoded = MetaValueReader::read($reader, MemcachedConstants::SERIALIZER_PHP, false);

        self::assertTrue($decoded->isFailure());
        self::assertSame(MemcachedConstants::RES_E2BIG, $decoded->errorCode);

        $followUp = (new MetaReader($connection, 10))->readOne(true);
        self::assertSame('HD', $followUp->code);

        fclose($server);
    }

    public function testSkipValueStillDrainsOversizedBody(): void
    {
        $payload = str_repeat('y', 15);
        $wire = 'VA 15 f0'."\r\n".$payload."\r\n".'END'."\r\n";
        [$connection, $server] = $this->socketConnection($wire);

        $reader = new MetaReader($connection, 5);
        $result = $reader->readOne(false);
        self::assertSame(MetaReader::CODE_ITEM_TOO_BIG, $result->code);

        $line = $connection->readLine();
        self::assertSame('END', $line);

        fclose($server);
    }
}
