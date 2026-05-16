<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientEncodingConfigurator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientEncodingConfiguratorTest extends TestCase
{
    public function testEmptyEncodingKeyIsRejected(): void
    {
        $env = $this->env();
        $configurator = new ClientEncodingConfigurator($env);

        self::assertFalse($configurator->setEncodingKey(''));
        self::assertSame(MemcachedConstants::RES_INVALID_ARGUMENTS, $env->getResultCode());
    }

    public function testEncodingRequiresOpenSsl(): void
    {
        if (\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is available');
        }

        $env = $this->env();
        $configurator = new ClientEncodingConfigurator($env);

        self::assertFalse($configurator->setEncodingKey('secret'));
        self::assertSame(MemcachedConstants::RES_NOT_SUPPORTED, $env->getResultCode());
    }

    public function testEncodingInstallsContextWhenOpenSslAvailable(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is not available');
        }

        $env = $this->env();
        $configurator = new ClientEncodingConfigurator($env);

        self::assertTrue($configurator->setEncodingKey('wire-secret'));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $env->getResultCode());
        self::assertNotNull($env->core->encoding);
    }

    public function testInvalidEncodingModeIsRejectedWhenOpenSslAvailable(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is not available');
        }

        $env = $this->env();
        $env->core->options[MemcachedConstants::OPT_ENCODING_MODE] = 999;
        $configurator = new ClientEncodingConfigurator($env);

        self::assertFalse($configurator->setEncodingKey('wire-secret'));
        self::assertSame(MemcachedConstants::RES_INVALID_ARGUMENTS, $env->getResultCode());
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
            static fn (string $_key): bool => true,
        );
    }
}
