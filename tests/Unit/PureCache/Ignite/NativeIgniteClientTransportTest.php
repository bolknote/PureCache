<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Ignite\Internal\IgniteProtocol;

final class NativeIgniteClientTransportTest extends TestCase
{
    #[DataProvider('transportRetryOpcodesProvider')]
    public function testAllowsTransportRetryOnlyForReadOnlyOpcodes(int $opCode, bool $expected): void
    {
        self::assertSame($expected, IgniteProtocol::allowsTransportRetry($opCode));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function transportRetryOpcodesProvider(): iterable
    {
        yield 'get' => [IgniteProtocol::OP_CACHE_GET, true];
        yield 'get all' => [IgniteProtocol::OP_CACHE_GET_ALL, true];
        yield 'get size' => [IgniteProtocol::OP_CACHE_GET_SIZE, true];
        yield 'resource close' => [IgniteProtocol::OP_RESOURCE_CLOSE, true];
        yield 'sql fields' => [IgniteProtocol::OP_QUERY_SQL_FIELDS, true];
        yield 'contains key' => [IgniteProtocol::OP_CACHE_CONTAINS_KEY, false];
        yield 'put' => [IgniteProtocol::OP_CACHE_PUT, false];
        yield 'put if absent' => [IgniteProtocol::OP_CACHE_PUT_IF_ABSENT, false];
        yield 'clear' => [IgniteProtocol::OP_CACHE_CLEAR, false];
        yield 'scan' => [IgniteProtocol::OP_QUERY_SCAN, false];
        yield 'scan page' => [IgniteProtocol::OP_QUERY_SCAN_CURSOR_GET_PAGE, false];
        yield 'get or create' => [IgniteProtocol::OP_CACHE_GET_OR_CREATE_WITH_NAME, false];
    }
}
