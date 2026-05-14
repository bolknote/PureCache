<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Memcached\MemcachedClient;

final class MemcachedIntegrationTest extends AbstractMemcachedLikeIntegrationTest
{
    protected static function integrationHost(): string
    {
        $host = getenv('MEMCACHED_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    protected static function integrationPort(): int
    {
        $port = getenv('MEMCACHED_TEST_PORT');

        return false !== $port ? (int) $port : 11211;
    }

    protected function createClient(): MemcachedClient
    {
        $m = new MemcachedClient();
        $m->addServer(self::integrationHost(), self::integrationPort());

        return $m;
    }
}
