<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientMultiKeyCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientMultiKeyCoordinatorTest extends TestCase
{
    public function testGetMultiRejectsInvalidServerKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $env = $this->env($core);
        $coordinator = $this->coordinator($env);

        self::assertFalse($coordinator->getMulti(['k'], '', 0));
        self::assertSame(MemcachedConstants::RES_BAD_KEY_PROVIDED, $core->resultCode);
    }

    public function testGetMultiReturnsEmptyArrayForEmptyKeyList(): void
    {
        $core = MemcachedClientCore::createFresh();
        $env = $this->env($core);
        $coordinator = $this->coordinator($env);

        self::assertSame([], $coordinator->getMulti([], null, 0));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $core->resultCode);
    }

    public function testGetMultiRejectsBadItemKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $env = $this->env($core);
        $coordinator = $this->coordinator($env);

        self::assertFalse($coordinator->getMulti([''], null, 0));
        self::assertSame(MemcachedConstants::RES_BAD_KEY_PROVIDED, $core->resultCode);
    }

    private function coordinator(ClientCoordinatorEnv $env): ClientMultiKeyCoordinator
    {
        return new ClientMultiKeyCoordinator(
            $env,
            static function (): void {
            },
            static function (array $keys): array {
                $strings = [];
                foreach ($keys as $key) {
                    $strings[] = \is_scalar($key) || null === $key ? (string) $key : '';
                }

                return $strings;
            },
            static fn (mixed $key): string => \is_scalar($key) || null === $key ? (string) $key : '',
            static fn (string $_key): bool => '' !== $_key,
            static fn (string $key): string => $key,
            static fn (): bool => true,
            static fn (): array => [],
            static fn (): bool => true,
            static fn (): bool => true,
        );
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
