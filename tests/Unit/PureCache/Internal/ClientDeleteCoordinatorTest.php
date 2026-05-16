<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientDeleteCoordinator;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;

final class ClientDeleteCoordinatorTest extends TestCase
{
    public function testAcceptDeleteTimeRejectsFutureTimestamps(): void
    {
        $coordinator = $this->coordinator(MemcachedClientCore::createFresh());
        self::assertFalse($coordinator->acceptDeleteTime(time() + 3600));
    }

    public function testAcceptDeleteTimeAllowsZero(): void
    {
        $coordinator = $this->coordinator(MemcachedClientCore::createFresh());
        self::assertTrue($coordinator->acceptDeleteTime(0));
    }

    private function coordinator(MemcachedClientCore $core): ClientDeleteCoordinator
    {
        $env = new ClientCoordinatorEnv(
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
        );

        return new ClientDeleteCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            static fn (string $_key): string => $_key,
        );
    }
}
