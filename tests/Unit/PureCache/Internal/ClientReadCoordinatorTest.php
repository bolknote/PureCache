<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientReadCoordinator;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientReadCoordinatorTest extends TestCase
{
    public function testGetRejectsInvalidServerKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 1]);

        $env = $this->env($core);

        $read = new ClientReadCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            static function (): void {
            },
            static fn (): mixed => true,
            static fn (): mixed => false,
        );

        self::assertFalse($read->get('k', 'k', '', null, 0));
        self::assertSame(MemcachedConstants::RES_BAD_KEY_PROVIDED, $core->resultCode);
    }

    public function testGetInvokesCacheCallbackWhenPrimaryMisses(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 1]);

        $env = $this->env($core);

        $read = new ClientReadCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            static function (): void {
            },
            static function () use ($core): mixed {
                $core->resultCode = MemcachedConstants::RES_NOTFOUND;

                return false;
            },
            static fn (callable $cb, string $key, ?string $serverKey, int $_flags): mixed => ($cb)(),
        );

        $value = $read->get(
            'k',
            'k',
            null,
            static fn (): string => 'from_cb',
            0,
        );

        self::assertSame('from_cb', $value);
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
            static fn (string $_key): bool => '' !== $_key,
        );
    }
}
