<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerEndpoint;

/**
 * {@see \PureCache\CacheClient::getServerList()} returns a {@code type} field
 * that PECL applications branch on. The TCP fallback is exercised throughout the
 * suite, but the SOCKET branch only fires for Unix socket paths and needs its
 * own coverage.
 */
final class ServerEndpointTest extends TestCase
{
    public function testReturnsTcpForRegularHostnamesAndIps(): void
    {
        self::assertSame('TCP', ServerEndpoint::listType('127.0.0.1'));
        self::assertSame('TCP', ServerEndpoint::listType('cache.internal'));
        self::assertSame('TCP', ServerEndpoint::listType('::1'));
        self::assertSame('TCP', ServerEndpoint::listType(''));
    }

    public function testRecognisesUnixSocketPaths(): void
    {
        self::assertSame('SOCKET', ServerEndpoint::listType('/var/run/memcached.sock'));
        self::assertSame('SOCKET', ServerEndpoint::listType('./relative/path.sock'));
    }

    public function testTreatsHostnameStartingWithDotButWithoutSlashAsTcp(): void
    {
        // libmemcached only switches to UNIX when the dotted name explicitly
        // contains a path separator (./socket); a bare ".local" hostname must
        // still resolve over TCP.
        self::assertSame('TCP', ServerEndpoint::listType('.local'));
    }
}
