<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Memcached;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Session\MemcachedSessionHandler;
use PureCache\Tests\Unit\PureCache\Support\FakeWireWorkerTrait;

final class MemcachedSessionHandlerWireTest extends TestCase
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

    public function testOpenReadWriteDestroyRoundTripOnFakeMeta(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $handler = new MemcachedSessionHandler();
        $savePath = '127.0.0.1:'.$port;

        self::assertTrue(@$handler->open($savePath, 'PHPSESSID'));
        self::assertTrue($handler->write('sess-1', 'payload-bytes'));
        self::assertSame('payload-bytes', $handler->read('sess-1'));
        self::assertTrue($handler->destroy('sess-1'));
        self::assertSame('', $handler->read('sess-1'));
        self::assertTrue($handler->close());
    }

    public function testOpenWithWeightedSavePathParsesServers(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $handler = new MemcachedSessionHandler();
        self::assertTrue(@$handler->open('127.0.0.1:'.$port.':5', 'PHPSESSID'));
        self::assertTrue($handler->write('weighted', 'ok'));
        self::assertSame('ok', $handler->read('weighted'));
        self::assertTrue($handler->close());
    }

    public function testOpenRejectsEmptySavePath(): void
    {
        $handler = new MemcachedSessionHandler();
        self::assertFalse(@$handler->open('', 'PHPSESSID'));
    }

    public function testCreateSidValidateIdAndUpdateTimestampOnFakeMeta(): void
    {
        $port = $this->reserveEphemeralPort();
        $this->wireWorkers[] = $this->startFakeWireWorker('fake_meta_store_server.php', [
            'FAKE_META_PORT' => (string) $port,
        ]);

        $handler = new MemcachedSessionHandler();
        self::assertTrue(@$handler->open('127.0.0.1:'.$port, 'PHPSESSID'));

        $sid = $handler->create_sid();
        self::assertNotSame('', $sid);
        self::assertTrue($handler->write($sid, 'payload'));
        self::assertTrue($handler->validateId($sid));
        self::assertTrue($handler->updateTimestamp($sid, 'payload'));
        self::assertTrue($handler->close());
    }
}
