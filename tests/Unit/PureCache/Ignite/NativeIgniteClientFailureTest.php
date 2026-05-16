<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\NativeIgniteClient;

final class NativeIgniteClientFailureTest extends TestCase
{
    public function testConnectToClosedPortThrows(): void
    {
        $client = new NativeIgniteClient('127.0.0.1', 9, 0.05);

        $this->expectException(\RuntimeException::class);
        $client->connect();
    }

    public function testCacheGetOnClosedPortThrowsAfterConnectAttempt(): void
    {
        $client = new NativeIgniteClient('127.0.0.1', 9, 0.05);

        try {
            $client->connect();
        } catch (\RuntimeException) {
            // expected
        }

        $this->expectException(\Throwable::class);
        $client->cacheGet(1, 'key');
    }
}
