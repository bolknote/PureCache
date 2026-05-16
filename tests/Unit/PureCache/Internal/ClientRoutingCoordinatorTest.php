<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;

final class ClientRoutingCoordinatorTest extends TestCase
{
    public function testPickServerIndexUsesKetamaWhenConfigured(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 100]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 100]);
        $core->options[MemcachedClient::OPT_LIBKETAMA_COMPATIBLE] = true;

        $env = $this->env($core);
        $routing = new ClientRoutingCoordinator($env);

        $first = $routing->pickServerIndex(null, 'user:1001');
        $again = $routing->pickServerIndex(null, 'user:1001');
        self::assertSame($first, $again);
        self::assertContains($first, [0, 1]);
    }

    public function testGroupKeysByServerBucketsKeys(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 0]);

        $routing = new ClientRoutingCoordinator($this->env($core));
        $groups = $routing->groupKeysByServer(['alpha', 'beta'], null);

        self::assertCount(1, $groups);
        self::assertArrayHasKey(0, $groups);
        self::assertCount(2, $groups[0]);
    }

    private function env(MemcachedClientCore $core): ClientCoordinatorEnv
    {
        return new ClientCoordinatorEnv(
            $core,
            static function (int $code, ?string $message = null) use ($core): void {
                $core->resultCode = $code;
                $core->resultMessage = $message ?? '';
            },
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => $core->optionInt($option, $default),
            static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
            static fn (string $key): string => $key,
            static fn (string $key): string => $key,
            static fn (string $_key): bool => true,
        );
    }
}
