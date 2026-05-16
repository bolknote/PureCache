<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ServerEndpoint;

final class ServerEndpointTest extends TestCase
{
    public function testListTypeClassifiesUnixSocketPaths(): void
    {
        self::assertSame('SOCKET', ServerEndpoint::listType('/var/run/memcached.sock'));
        self::assertSame('SOCKET', ServerEndpoint::listType('./run/memcached.sock'));
        self::assertSame('TCP', ServerEndpoint::listType('127.0.0.1'));
    }
}
