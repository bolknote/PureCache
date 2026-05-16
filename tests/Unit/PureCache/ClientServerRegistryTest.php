<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientServerRegistry;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

final class ClientServerRegistryTest extends TestCase
{
    public function testAddServerParsesRedisUrl(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertTrue($registry->addServer('redis://cache.example.test:6379/2'));
        self::assertSame(MemcachedClient::RES_SUCCESS, $core->resultCode);

        $servers = $registry->getServerList();
        self::assertCount(1, $servers);
        self::assertSame('cache.example.test', $servers[0]['host']);
        self::assertSame(6379, $servers[0]['port']);
    }

    public function testSetBucketRejectsEmptyMap(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        $warned = false;
        set_error_handler(static function (int $severity, string $_message) use (&$warned): bool {
            if (\E_USER_WARNING === $severity) {
                $warned = true;
            }

            return true;
        }, \E_USER_WARNING);

        try {
            self::assertFalse($registry->setBucket([], null, 0));
        } finally {
            restore_error_handler();
        }

        self::assertTrue($warned);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $core->resultCode);
    }

    public function testRedisClientAddServerAcceptsRedissScheme(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServer('rediss://secure.example.test:6380'));
        self::assertCount(1, $client->getServerList());
    }

    private function registry(MemcachedClientCore $core): ClientServerRegistry
    {
        return new ClientServerRegistry(
            new \PureCache\Internal\ClientCoordinatorEnv(
                $core,
                static function (int $code, ?string $message = null) use ($core): void {
                    $core->resultCode = $code;
                    $core->resultMessage = $message ?? '';
                },
                static fn (): int => $core->resultCode,
                static fn (int $option, int $default): int => $core->optionInt($option, $default),
                static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
                static fn (string $_key): string => $_key,
                static fn (string $_key): string => $_key,
                static fn (string $_key): bool => true,
            ),
            static function (): void {},
            static fn (): int => 11211,
            static fn (string $_serverKey): bool => true,
        );
    }
}
