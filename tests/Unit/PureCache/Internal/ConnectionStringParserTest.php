<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ConnectionStringParser;

final class ConnectionStringParserTest extends TestCase
{
    public function testParseServersSkipsEmptyTokensBetweenDelimiters(): void
    {
        $servers = ConnectionStringParser::parseServers('  ,  , 127.0.0.1:11211  ');

        self::assertCount(1, $servers);
        self::assertSame('127.0.0.1', $servers[0]['host']);
        self::assertSame(11211, $servers[0]['port']);
    }

    public function testParseServersAcceptsLowercaseServerFlagPrefix(): void
    {
        $servers = ConnectionStringParser::parseServers('--server=cache.local:11211');

        self::assertCount(1, $servers);
        self::assertSame('cache.local', $servers[0]['host']);
    }
}
