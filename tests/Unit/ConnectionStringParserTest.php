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

    public function testIgnoresWhitespaceOnlyServerPrefixValue(): void
    {
        self::assertSame(
            [['host' => 'cache', 'port' => 11211, 'weight' => 0]],
            ConnectionStringParser::parseServers('--SERVER=   ,cache:11211'),
        );
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

    public function testParsesRedissUrlEnablesTls(): void
    {
        $servers = ConnectionStringParser::parseServers('rediss://host:6380');
        self::assertSame([['host' => 'host', 'port' => 6380, 'weight' => 0, 'tls' => true]], $servers);
    }

    public function testParsesRedissUrlDefaultsPort6380(): void
    {
        $servers = ConnectionStringParser::parseServers('rediss://cache.example');
        self::assertSame([['host' => 'cache.example', 'port' => 6380, 'weight' => 0, 'tls' => true]], $servers);
    }

    public function testParsesRedissUrlCafileQueryParameter(): void
    {
        $servers = ConnectionStringParser::parseServers('rediss://cache.example:6380?cafile=%2Fetc%2Fssl%2Fca.pem');
        self::assertSame([
            [
                'host' => 'cache.example',
                'port' => 6380,
                'weight' => 0,
                'tls' => true,
                'tls_ca_file' => '/etc/ssl/ca.pem',
            ],
        ], $servers);
    }

    public function testRedisUrlWithoutHostIsRejected(): void
    {
        // parse_url() yields {scheme:redis} but no host — must drop the entry
        // rather than producing an unaddressable {host:''} record.
        self::assertSame([], ConnectionStringParser::parseServers('redis://'));
    }

    public function testRedisUrlWithNonNumericDatabaseIsIgnored(): void
    {
        // The DB suffix is best-effort: callers expect an int, so when the
        // path segment isn't `ctype_digit` we drop the field entirely instead
        // of casting it to 0 (which would silently rebind clients to db 0).
        self::assertSame(
            [['host' => 'cache', 'port' => 6379, 'weight' => 0]],
            ConnectionStringParser::parseServers('redis://cache:6379/main'),
        );
    }

    public function testRedisUrlWithTrailingSlashHasNoDatabase(): void
    {
        // `/` after the port produces an empty path segment after ltrim — the
        // parser must NOT emit `database => 0` for this case (path is just
        // delimiter noise, not a real DB selector).
        self::assertSame(
            [['host' => 'cache', 'port' => 6379, 'weight' => 0]],
            ConnectionStringParser::parseServers('redis://cache:6379/'),
        );
    }

    public function testBracketedIpv6WithoutPortIsRejected(): void
    {
        // `[::1]` alone has neither port nor weight, so neither bracket-form
        // regex matches; we must drop it rather than fall through to the
        // unbracketed code path where `strrpos($host, ':')` would split the
        // IPv6 literal on its own colons.
        self::assertSame([], ConnectionStringParser::parseServers('[::1]'));
    }

    public function testParseServersStripsExtraWhitespaceBetweenTokens(): void
    {
        // Mixed delimiters (spaces, tabs, newlines, commas) is what
        // libmemcached's `--SERVER=` parser tolerates; preg_split with
        // PREG_SPLIT_NO_EMPTY is supposed to flatten them silently.
        $servers = ConnectionStringParser::parseServers(",,  cache-a:11211 \t\n cache-b:11212  ,");
        self::assertSame([
            ['host' => 'cache-a', 'port' => 11211, 'weight' => 0],
            ['host' => 'cache-b', 'port' => 11212, 'weight' => 0],
        ], $servers);
    }
}
