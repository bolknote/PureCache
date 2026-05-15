<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteCommandResultMapper;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteTransportFailure;
use PureCache\MemcachedConstants;

final class IgniteCommandResultMapperTest extends TestCase
{
    #[DataProvider('statusMappingsProvider')]
    public function testMapsIgniteStatusToMemcachedResult(int $status, int $expected): void
    {
        self::assertSame($expected, IgniteCommandResultMapper::toResultCode($status));
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function statusMappingsProvider(): iterable
    {
        yield 'auth failed' => [IgniteProtocol::STATUS_AUTH_FAILED, MemcachedConstants::RES_AUTH_FAILURE];
        yield 'security violation' => [IgniteProtocol::STATUS_SECURITY_VIOLATION, MemcachedConstants::RES_AUTH_FAILURE];
        yield 'node recovery' => [IgniteProtocol::STATUS_NODE_IN_RECOVERY_MODE, MemcachedConstants::RES_SERVER_TEMPORARILY_DISABLED];
        yield 'resource missing' => [IgniteProtocol::STATUS_RESOURCE_DOES_NOT_EXIST, MemcachedConstants::RES_NOTFOUND];
        yield 'cache missing' => [IgniteProtocol::STATUS_CACHE_DOES_NOT_EXIST, MemcachedConstants::RES_DATA_DOES_NOT_EXIST];
        yield 'unknown' => [IgniteProtocol::STATUS_FAILED, MemcachedConstants::RES_FAILURE];
    }

    #[DataProvider('transportMappingsProvider')]
    public function testMapsTransportFailureToMemcachedResult(IgniteTransportFailure $reason, int $expected): void
    {
        self::assertSame($expected, IgniteCommandResultMapper::transportToResultCode($reason));
    }

    /**
     * @return iterable<string, array{0: IgniteTransportFailure, 1: int}>
     */
    public static function transportMappingsProvider(): iterable
    {
        yield 'not connected' => [IgniteTransportFailure::NotConnected, MemcachedConstants::RES_CONNECTION_FAILURE];
        yield 'connect failed' => [IgniteTransportFailure::ConnectFailed, MemcachedConstants::RES_CONNECTION_FAILURE];
        yield 'handshake failed' => [IgniteTransportFailure::HandshakeFailed, MemcachedConstants::RES_CONNECTION_FAILURE];
        yield 'connection closed' => [IgniteTransportFailure::ConnectionClosed, MemcachedConstants::RES_CONNECTION_FAILURE];
        yield 'read timeout' => [IgniteTransportFailure::ReadTimedOut, MemcachedConstants::RES_TIMEOUT];
        yield 'write timeout' => [IgniteTransportFailure::WriteTimedOut, MemcachedConstants::RES_TIMEOUT];
        yield 'read truncated' => [IgniteTransportFailure::ReadTruncated, MemcachedConstants::RES_READ_FAILURE];
        yield 'write failed' => [IgniteTransportFailure::WriteFailed, MemcachedConstants::RES_READ_FAILURE];
        yield 'frame length invalid' => [IgniteTransportFailure::FrameLengthInvalid, MemcachedConstants::RES_FAILURE];
        yield 'frame length exceeded' => [IgniteTransportFailure::FrameLengthExceeded, MemcachedConstants::RES_FAILURE];
        yield 'reply too short' => [IgniteTransportFailure::ReplyTooShort, MemcachedConstants::RES_FAILURE];
        yield 'request id mismatch' => [IgniteTransportFailure::RequestIdMismatch, MemcachedConstants::RES_FAILURE];
        yield 'retry exhausted' => [IgniteTransportFailure::RetryExhausted, MemcachedConstants::RES_FAILURE];
    }

    public function testMapsEveryTransportFailureCase(): void
    {
        self::assertCount(
            \count(IgniteTransportFailure::cases()),
            iterator_to_array(self::transportMappingsProvider(), false),
            'transportMappingsProvider must cover every IgniteTransportFailure case',
        );
    }
}
