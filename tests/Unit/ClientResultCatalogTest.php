<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientResultCatalog;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

final class ClientResultCatalogTest extends TestCase
{
    public function testCannotInstantiateCatalogDirectly(): void
    {
        $reflection = new \ReflectionClass(ClientResultCatalog::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($instance);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function cataloguedResultCodesProvider(): iterable
    {
        yield 'SUCCESS' => [MemcachedConstants::RES_SUCCESS, 'SUCCESS'];
        yield 'END' => [MemcachedConstants::RES_END, 'END'];
        yield 'NOTFOUND' => [MemcachedConstants::RES_NOTFOUND, 'NOT FOUND'];
        yield 'DATA_EXISTS' => [MemcachedConstants::RES_DATA_EXISTS, 'DATA EXISTS'];
        yield 'NOTSTORED' => [MemcachedConstants::RES_NOTSTORED, 'NOT STORED'];
        yield 'FAILURE' => [MemcachedConstants::RES_FAILURE, 'FAILURE'];
        yield 'NO_SERVERS' => [MemcachedConstants::RES_NO_SERVERS, 'NO SERVERS'];
        yield 'BAD_KEY' => [MemcachedConstants::RES_BAD_KEY_PROVIDED, 'BAD KEY'];
        yield 'PAYLOAD_FAILURE' => [MemcachedConstants::RES_PAYLOAD_FAILURE, 'PAYLOAD FAILURE'];
        yield 'NOT_SUPPORTED' => [MemcachedConstants::RES_NOT_SUPPORTED, 'NOT SUPPORTED'];
        yield 'INVALID_ARGUMENTS' => [MemcachedConstants::RES_INVALID_ARGUMENTS, 'INVALID ARGUMENTS'];
        yield 'INVALID_HOST_PROTOCOL' => [MemcachedConstants::RES_INVALID_HOST_PROTOCOL, 'INVALID HOST PROTOCOL'];
        yield 'E2BIG' => [MemcachedConstants::RES_E2BIG, 'ITEM TOO BIG'];
        yield 'FETCH_NOTFINISHED' => [MemcachedConstants::RES_FETCH_NOTFINISHED, 'FETCH NOT FINISHED'];
        yield 'SOME_ERRORS' => [MemcachedConstants::RES_SOME_ERRORS, 'SOME ERRORS WERE REPORTED'];
        yield 'WRITE_FAILURE' => [MemcachedConstants::RES_WRITE_FAILURE, 'WRITE FAILURE'];
        yield 'PARTIAL_READ' => [MemcachedConstants::RES_PARTIAL_READ, 'PARTIAL READ'];
        yield 'BUFFERED' => [MemcachedConstants::RES_BUFFERED, 'BUFFERED'];
        yield 'SERVER_TEMPORARILY_DISABLED' => [MemcachedConstants::RES_SERVER_TEMPORARILY_DISABLED, 'SERVER TEMPORARILY DISABLED'];
        yield 'SERVER_MEMORY_ALLOCATION_FAILURE' => [MemcachedConstants::RES_SERVER_MEMORY_ALLOCATION_FAILURE, 'SERVER MEMORY ALLOCATION FAILURE'];
        yield 'AUTH_PROBLEM' => [MemcachedConstants::RES_AUTH_PROBLEM, 'AUTH PROBLEM'];
        yield 'AUTH_FAILURE' => [MemcachedConstants::RES_AUTH_FAILURE, 'AUTH FAILURE'];
        yield 'AUTH_CONTINUE' => [MemcachedConstants::RES_AUTH_CONTINUE, 'AUTH CONTINUE'];
    }

    #[DataProvider('cataloguedResultCodesProvider')]
    public function testDefaultMessageMapsPeclResultCodes(int $code, string $expected): void
    {
        self::assertSame($expected, ClientResultCatalog::defaultMessage($code));
    }

    public function testUnknownResultCodeFallsBackToUnknown(): void
    {
        self::assertSame('UNKNOWN', ClientResultCatalog::defaultMessage(999_999));
    }

    public function testMemcachedClientSurfacesCatalogMessageWhenResultSetWithoutCustomText(): void
    {
        $client = new MemcachedClient();
        $client->set('key', 'v');
        self::assertSame(MemcachedConstants::RES_NO_SERVERS, $client->getResultCode());
        self::assertSame('NO SERVERS', $client->getResultMessage());
    }
}
