<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientLifecycleHelper;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientLifecycleHelperTest extends TestCase
{
    public function testQuitSucceedsWhenPoolInvalidationSucceeds(): void
    {
        $env = $this->env();
        $helper = new ClientLifecycleHelper(
            $env,
            static function (): void {},
            static function (): void {},
        );

        self::assertTrue($helper->quit());
        self::assertSame(MemcachedConstants::RES_SUCCESS, $env->getResultCode());
    }

    public function testQuitMapsPoolInvalidationFailureToWriteFailure(): void
    {
        $env = $this->env();
        $helper = new ClientLifecycleHelper(
            $env,
            static function (): never {
                throw new \RuntimeException('pool close failed');
            },
            static function (): void {},
        );

        self::assertFalse($helper->quit());
        self::assertSame(MemcachedConstants::RES_WRITE_FAILURE, $env->getResultCode());
        self::assertSame('pool close failed', $env->core->resultMessage);
    }

    public function testFlushBuffersSucceedsWhenNetworkFlushSucceeds(): void
    {
        $env = $this->env();
        $flushed = false;
        $helper = new ClientLifecycleHelper(
            $env,
            static function (): void {},
            static function () use (&$flushed): void {
                $flushed = true;
            },
        );

        self::assertTrue($helper->flushBuffers());
        self::assertTrue($flushed);
        self::assertSame(MemcachedConstants::RES_SUCCESS, $env->getResultCode());
    }

    public function testFlushBuffersMapsNetworkFailureToWriteFailure(): void
    {
        $env = $this->env();
        $helper = new ClientLifecycleHelper(
            $env,
            static function (): void {},
            static function (): never {
                throw new \RuntimeException('flush failed');
            },
        );

        self::assertFalse($helper->flushBuffers());
        self::assertSame(MemcachedConstants::RES_WRITE_FAILURE, $env->getResultCode());
        self::assertSame('flush failed', $env->core->resultMessage);
    }

    private function env(): ClientCoordinatorEnv
    {
        $core = MemcachedClientCore::createFresh();

        return new ClientCoordinatorEnv(
            $core,
            static function (int $code, ?string $message = null) use ($core): void {
                $core->resultCode = $code;
                $core->resultMessage = $message ?? '';
            },
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => \is_int($core->options[$option] ?? null)
                ? $core->options[$option]
                : $default,
            static fn (int $option, bool $default): bool => \is_bool($core->options[$option] ?? null)
                ? $core->options[$option]
                : $default,
            static fn (string $key): string => $key,
            static fn (string $key): string => $key,
            static fn (string $_key): bool => '' !== $_key,
        );
    }
}
