<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ConnectionStringParser;

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

    public function testIpv6LiteralWithoutBracketsIsRejected(): void
    {
        self::assertSame([], ConnectionStringParser::parseServers('::1:11211'));
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

    public function testParsesRedisUrlWithoutAuthOrDatabase(): void
    {
        self::assertSame(
            [['host' => 'redis-1', 'port' => 6380, 'weight' => 0]],
            ConnectionStringParser::parseServers('redis://redis-1:6380'),
        );
    }

    public function testParsesRedisUrlWithoutPortDefersDefaulting(): void
    {
        self::assertSame(
            [['host' => 'redis-1', 'port' => 0, 'weight' => 0]],
            ConnectionStringParser::parseServers('redis://redis-1'),
        );
    }

    public function testParsesRedisUrlWithAclUserAndPassword(): void
    {
        self::assertSame(
            [[
                'host' => 'cache',
                'port' => 6379,
                'weight' => 0,
                'user' => 'svc',
                'password' => 's3cr3t!',
                'database' => 3,
            ]],
            ConnectionStringParser::parseServers('redis://svc:s3cr3t%21@cache:6379/3'),
        );
    }

    public function testParsesRedisUrlWithPasswordOnly(): void
    {
        self::assertSame(
            [[
                'host' => 'cache',
                'port' => 6379,
                'weight' => 0,
                'password' => 'p@ss',
            ]],
            ConnectionStringParser::parseServers('redis://:p%40ss@cache:6379'),
        );
    }

    public function testParsesRedissUrlAsRegularRedis(): void
    {
        $servers = ConnectionStringParser::parseServers('rediss://host:6380');
        self::assertSame([['host' => 'host', 'port' => 6380, 'weight' => 0]], $servers);
    }
}
