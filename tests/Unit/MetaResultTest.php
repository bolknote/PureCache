<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedConstants;
use PureMemcached\Internal\MetaResult;

final class MetaResultTest extends TestCase
{
    public function testParseVaLine(): void
    {
        $r = MetaResult::fromLine('VA 5 f1 c42 t10');
        self::assertSame('VA', $r->code);
        self::assertSame('1', $r->getToken('f'));
        self::assertSame('42', $r->getToken('c'));
        self::assertSame('10', $r->getToken('t'));
    }

    public function testClientError(): void
    {
        $r = MetaResult::fromLine('CLIENT_ERROR bad');
        self::assertSame('CLIENT_ERROR', $r->code);
        self::assertSame('bad', $r->errorMessage);
        self::assertSame(MemcachedConstants::RES_CLIENT_ERROR, $r->wireErrorResultCode());
    }

    public function testServerErrorWireCode(): void
    {
        $r = MetaResult::fromLine('SERVER_ERROR READONLY no writes');
        self::assertSame(MemcachedConstants::RES_SERVER_ERROR, $r->wireErrorResultCode());
    }

    public function testNormalVaHasNoWireErrorCode(): void
    {
        $r = MetaResult::fromLine('VA 5 f1 c42 t10');
        self::assertNull($r->wireErrorResultCode());
    }

    public function testEmptyLineIsErrorResult(): void
    {
        $r = MetaResult::fromLine('');
        self::assertSame('', $r->code);
        self::assertSame([], $r->tokens);
        self::assertSame('Empty response line', $r->errorMessage);
        self::assertSame(MemcachedConstants::RES_PROTOCOL_ERROR, $r->wireErrorResultCode());
    }

    public function testPlainErrorLineMapsToFailure(): void
    {
        $r = MetaResult::fromLine('ERROR out of memory');
        self::assertSame(MemcachedConstants::RES_FAILURE, $r->wireErrorResultCode());
    }

    public function testValueAndTypedTokenHelpers(): void
    {
        $r = MetaResult::fromLine('VA 0 f65536 c123')->withValue('');
        self::assertSame('', $r->value);
        self::assertSame('123', $r->getCas());
        self::assertSame(65536, $r->getClientFlags());
        self::assertSame('fallback', $r->getToken('x', 'fallback'));
    }
}
