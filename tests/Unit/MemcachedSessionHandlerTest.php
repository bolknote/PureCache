<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\CacheClient;
use PureCache\Memcached\Session\MemcachedSessionHandler;
use PureCache\MemcachedConstants;

final class MemcachedSessionHandlerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $flag = new \ReflectionProperty(MemcachedSessionHandler::class, 'binaryProtocolWarned');
        $flag->setValue(null, false);
    }

    public function testOpenRejectsPersistentSavePathMarker(): void
    {
        $handler = new MemcachedSessionHandler();
        $warnings = $this->captureWarnings(static fn (): bool => $handler->open('127.0.0.1:11211?PERSISTENT=foo', 'PHPSESSID'));

        self::assertFalse($warnings['result']);
        self::assertStringContainsString('PERSISTENT', $warnings['message']);
    }

    public function testOpenRejectsEmptySavePath(): void
    {
        $handler = new MemcachedSessionHandler();
        $warnings = $this->captureWarnings(static fn (): bool => $handler->open('', 'PHPSESSID'));

        self::assertFalse($warnings['result']);
        self::assertStringContainsString('failed to parse session.save_path', $warnings['message']);
    }

    public function testReadWithoutOpenWarnsAndReturnsFalse(): void
    {
        $handler = new MemcachedSessionHandler();
        $warnings = $this->captureWarnings(static fn (): string|false => $handler->read('sid'));

        self::assertFalse($warnings['result']);
        self::assertStringContainsString('Session is not allocated', $warnings['message']);
    }

    public function testWriteAndReadRoundTripWithInjectedClient(): void
    {
        $client = $this->createMock(CacheClient::class);
        $client->method('get')->willReturnCallback(static fn (string $key): false|string => 'lock.sid' === $key ? false : 'payload');
        $client->method('getResultCode')->willReturn(MemcachedConstants::RES_SUCCESS);
        $client->method('getResultMessage')->willReturn('SUCCESS');
        $client->method('add')->willReturn(true);
        $client->method('set')->willReturn(true);
        $client->method('setOption')->willReturn(true);

        $handler = new MemcachedSessionHandler($client);
        self::assertTrue($this->openSession($handler));
        self::assertSame('payload', $handler->read('sid'));
        self::assertTrue($handler->write('sid', 'payload'));
        self::assertTrue($handler->destroy('sid'));
        self::assertTrue($handler->close());
    }

    public function testReadReturnsEmptyStringOnNotFound(): void
    {
        $client = $this->createMock(CacheClient::class);
        $client->method('get')->willReturn(false);
        $client->method('getResultCode')->willReturnOnConsecutiveCalls(
            MemcachedConstants::RES_SUCCESS,
            MemcachedConstants::RES_NOTFOUND,
        );
        $client->method('add')->willReturn(true);
        $client->method('setOption')->willReturn(true);

        $handler = new MemcachedSessionHandler($client);
        self::assertTrue($this->openSession($handler));

        self::assertSame('', $handler->read('sid'));
    }

    public function testGcIsNoOp(): void
    {
        self::assertSame(0, (new MemcachedSessionHandler())->gc(3600));
    }

    public function testCreateSidUsesRandomWhenClientMissing(): void
    {
        $handler = new MemcachedSessionHandler();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $handler->create_sid());
    }

    public function testValidateIdReturnsFalseWithoutClient(): void
    {
        self::assertFalse((new MemcachedSessionHandler())->validateId('sid'));
    }

    public function testUpdateTimestampReturnsFalseWithoutClient(): void
    {
        self::assertFalse((new MemcachedSessionHandler())->updateTimestamp('sid', 'data'));
    }

    public function testOpenWithRealClientConfiguresServers(): void
    {
        $handler = new MemcachedSessionHandler();
        self::assertTrue($this->openSession($handler, '127.0.0.1:11211,127.0.0.1:11212:5'));
        self::assertTrue($handler->close());
    }

    public function testReadFailsWhenLockCannotBeAcquired(): void
    {
        $client = $this->createMock(CacheClient::class);
        $client->method('setOption')->willReturn(true);
        $client->method('add')->willReturn(false);
        $client->method('getResultCode')->willReturn(MemcachedConstants::RES_NOTSTORED);

        $handler = new MemcachedSessionHandler($client);
        self::assertTrue($this->openSession($handler));

        $warnings = $this->captureWarnings(static fn (): string|false => $handler->read('sid'));
        self::assertFalse($warnings['result']);
        self::assertStringContainsString('Unable to clear session lock', $warnings['message']);
    }

    public function testReadFailsOnTransportError(): void
    {
        $client = $this->createMock(CacheClient::class);
        $client->method('add')->willReturn(true);
        $client->method('get')->willReturn(false);
        $client->method('getResultCode')->willReturn(MemcachedConstants::RES_FAILURE);
        $client->method('getResultMessage')->willReturn('FAIL');
        $client->method('setOption')->willReturn(true);

        $handler = new MemcachedSessionHandler($client);
        self::assertTrue($this->openSession($handler));

        $warnings = $this->captureWarnings(static fn (): string|false => $handler->read('sid'));
        self::assertFalse($warnings['result']);
        self::assertStringContainsString('error getting session', $warnings['message']);
    }

    public function testWriteFailsWhenSetDoesNotSucceed(): void
    {
        $client = $this->createMock(CacheClient::class);
        $client->method('add')->willReturn(true);
        $client->method('set')->willReturn(false);
        $client->method('getResultCode')->willReturn(MemcachedConstants::RES_FAILURE);
        $client->method('getResultMessage')->willReturn('FAIL');
        $client->method('setOption')->willReturn(true);

        $handler = new MemcachedSessionHandler($client);
        self::assertTrue($this->openSession($handler));

        $warnings = $this->captureWarnings(static fn (): bool => $handler->write('sid', 'data'));
        self::assertFalse($warnings['result']);
        self::assertStringContainsString('error saving session', $warnings['message']);
    }

    /**
     * Suppresses the one-shot {@code memcached.sess_binary_protocol} warning that
     * {@see MemcachedSessionHandler::configureFromIni()} emits when the INI default is On.
     */
    private function openSession(
        MemcachedSessionHandler $handler,
        string $path = '127.0.0.1:11211',
        string $name = 'PHPSESSID',
    ): bool {
        return @$handler->open($path, $name);
    }

    /**
     * @param callable(): mixed $action
     *
     * @return array{result: mixed, message: string}
     */
    private function captureWarnings(callable $action): array
    {
        $message = '';
        set_error_handler(static function (int $severity, string $msg) use (&$message): bool {
            if (\E_USER_WARNING === $severity) {
                $message = $msg;
            }

            return true;
        });

        try {
            return ['result' => $action(), 'message' => $message];
        } finally {
            restore_error_handler();
        }
    }
}
