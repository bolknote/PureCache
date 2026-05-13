<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Internal\ConnectionStringParser;

final class ConnectionStringParserTest extends TestCase
{
    public function testParsesCommaSeparatedHosts(): void
    {
        $list = ConnectionStringParser::parseServers('127.0.0.1:11211, 10.0.0.1:11212');
        self::assertSame([
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0],
            ['host' => '10.0.0.1', 'port' => 11212, 'weight' => 0],
        ], $list);
    }

    public function testParsesServerPrefixAndWeight(): void
    {
        $list = ConnectionStringParser::parseServers('--SERVER=cache:11211:2');
        self::assertSame([['host' => 'cache', 'port' => 11211, 'weight' => 2]], $list);
    }

    public function testParsesLowercaseServerPrefixAndWhitespaceSeparatedHosts(): void
    {
        $list = ConnectionStringParser::parseServers("--server=cache-a:11211:1\ncache-b:11212");
        self::assertSame([
            ['host' => 'cache-a', 'port' => 11211, 'weight' => 1],
            ['host' => 'cache-b', 'port' => 11212, 'weight' => 0],
        ], $list);
    }

    public function testParsesIpv6BracketForm(): void
    {
        $list = ConnectionStringParser::parseServers('[::1]:11211');
        self::assertSame([['host' => '::1', 'port' => 11211, 'weight' => 0]], $list);
    }

    public function testParsesIpv6BracketFormWithWeight(): void
    {
        $list = ConnectionStringParser::parseServers('[::1]:11211:4');
        self::assertSame([['host' => '::1', 'port' => 11211, 'weight' => 4]], $list);
    }

    public function testIgnoresInvalidTokens(): void
    {
        self::assertSame([], ConnectionStringParser::parseServers('missing-port [::1] --SERVER='));
    }

    public function testEmptyStringReturnsEmptyList(): void
    {
        self::assertSame([], ConnectionStringParser::parseServers(''));
        self::assertSame([], ConnectionStringParser::parseServers('   '));
    }
}
