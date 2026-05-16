<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerEndpoint;

final class ServerEndpointTest extends TestCase
{
    public function testUnixSocketPathIsClassifiedAsSocket(): void
    {
        self::assertSame('SOCKET', ServerEndpoint::listType('/var/run/memcached.sock'));
    }

    public function testRelativeUnixPathIsClassifiedAsSocket(): void
    {
        self::assertSame('SOCKET', ServerEndpoint::listType('./run/memcached.sock'));
    }

    public function testHostnameIsTcp(): void
    {
        self::assertSame('TCP', ServerEndpoint::listType('127.0.0.1'));
    }
}
