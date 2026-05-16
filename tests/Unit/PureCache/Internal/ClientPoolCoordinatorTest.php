<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ClientPoolCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;

final class ClientPoolCoordinatorTest extends TestCase
{
    public function testCollectFromServersMapsNullResultsToFailureValue(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);

        $env = $this->env($core);
        $pool = new ClientPoolCoordinator($env, new ClientHealthRecorder($env));

        $result = $pool->collectFromServers(static fn (): ?string => null, 'fail');

        self::assertSame(
            ['a:11211' => 'fail', 'b:11211' => 'fail'],
            $result['values'],
        );
        self::assertFalse($result['allOk']);
        self::assertFalse($result['anyOk']);
    }

    public function testCollectFromServersRecordsThrowableAsFailure(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 6379, 'weight' => 1]);

        $env = $this->env($core);
        $health = new ClientHealthRecorder($env);
        $pool = new ClientPoolCoordinator($env, $health);

        $result = $pool->collectFromServers(
            static function (): string {
                throw new \RuntimeException('wire down');
            },
            false,
        );

        self::assertSame(['solo:6379' => false], $result['values']);
        self::assertFalse($result['allOk']);
        self::assertFalse($result['anyOk']);
    }

    public function testCollectFromServersMarksPartialSuccess(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'ok', 'port' => 1, 'weight' => 1]);
        $core->selector->addServer(['host' => 'bad', 'port' => 2, 'weight' => 1]);

        $env = $this->env($core);
        $pool = new ClientPoolCoordinator($env, new ClientHealthRecorder($env));

        $result = $pool->collectFromServers(
            static fn (int $index): ?int => 0 === $index ? 7 : null,
            -1,
        );

        self::assertSame(['ok:1' => 7, 'bad:2' => -1], $result['values']);
        self::assertFalse($result['allOk']);
        self::assertTrue($result['anyOk']);
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
