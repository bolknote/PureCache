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
    public function testSetEncodingKeyRejectsEmptyKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $configurator = new ClientEncodingConfigurator($this->env($core));

        self::assertFalse($configurator->setEncodingKey(''));
        self::assertSame(MemcachedConstants::RES_INVALID_ARGUMENTS, $core->resultCode);
    }

    public function testSetEncodingKeyRejectsUnknownEncodingMode(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is not loaded');
        }

        $core = MemcachedClientCore::createFresh();
        $core->options[MemcachedConstants::OPT_ENCODING_MODE] = 99;
        $configurator = new ClientEncodingConfigurator($this->env($core));

        self::assertFalse($configurator->setEncodingKey('valid-key'));
        self::assertSame(MemcachedConstants::RES_INVALID_ARGUMENTS, $core->resultCode);
    }

    public function testSetEncodingKeyInstallsContextWhenOpenSslIsAvailable(): void
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is not loaded');
        }

        $core = MemcachedClientCore::createFresh();
        $configurator = new ClientEncodingConfigurator($this->env($core));

        self::assertTrue($configurator->setEncodingKey('user-supplied-encoding-key'));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $core->resultCode);
        self::assertNotNull($core->encoding);
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
