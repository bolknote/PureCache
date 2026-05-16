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

    public function testAddServerUsesLocalhostWhenHostEmpty(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertTrue($registry->addServer('', 11211));
        self::assertSame('localhost', $registry->getServerList()[0]['host']);
    }

    public function testAddServerRejectsNegativePort(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertFalse($registry->addServer('127.0.0.1', -1));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $core->resultCode);
    }

    public function testAddServerRejectsInvalidConnectionString(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertFalse($registry->addServer('not-a-valid-scheme://'));
        self::assertSame(MemcachedClient::RES_FAILURE, $core->resultCode);
    }

    public function testAddServersAcceptsListTuplesWithCredentials(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertTrue($registry->addServers([
            ['host' => 'h', 'port' => 11211, 'weight' => 1, 'user' => 'u', 'password' => 'p', 'database' => 2],
        ]));
        $internal = $core->selector->getServers()[0];
        self::assertSame('u', $internal['user'] ?? null);
        self::assertSame('p', $internal['password'] ?? null);
        self::assertSame(2, $internal['database'] ?? null);
    }

    public function testAddServersAcceptsNumericListTuples(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertTrue($registry->addServers([['cache', 11211, 3]]));
        $internal = $core->selector->getServers()[0];
        self::assertSame('cache', $internal['host']);
        self::assertSame(11211, $internal['port']);
        self::assertSame(3, $internal['weight']);
    }

    public function testAddServerOverridesPortAndWeightForSingleParsedUrl(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core);

        self::assertTrue($registry->addServer('redis://cache.example.test:6379', 6380, 5));
        $internal = $core->selector->getServers()[0];
        self::assertSame(6380, $internal['port']);
        self::assertSame(5, $internal['weight']);
    }

    public function testGetServerByKeyRejectsInvalidServerKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $registry = $this->registry($core, checkServerKey: static fn (string $key): bool => 'valid' === $key);
        $registry->addServer('127.0.0.1', 11211);

        self::assertFalse($registry->getServerByKey('not-valid'));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $core->resultCode);
    }

    public function testRedisClientAddServerAcceptsRedissScheme(): void
    {
        $client = new RedisClient();
        self::assertTrue($client->addServer('rediss://secure.example.test:6380'));
        self::assertCount(1, $client->getServerList());
    }

    /**
     * @param \Closure(string): bool|null $checkServerKey
     */
    private function registry(MemcachedClientCore $core, ?\Closure $checkServerKey = null): ClientServerRegistry
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
                $checkServerKey ?? static fn (string $_key): bool => true,
            ),
            static function (): void {},
            static fn (): int => 11211,
            $checkServerKey ?? static fn (string $_key): bool => true,
        );
    }
}
