<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ClientKeyedExecutor;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

final class ClientKeyedExecutorTest extends TestCase
{
    public function testExecuteKeyedFanoutInvokesPrimaryAndReplicas(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'c', 'port' => 11211, 'weight' => 1]);
        $core->options[MemcachedClient::OPT_NUMBER_OF_REPLICAS] = 2;

        $env = $this->env($core);
        $executor = new ClientKeyedExecutor(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        $hits = [];
        $result = $executor->executeKeyed(
            'item',
            null,
            static function (int $idx, string $pk) use (&$hits): bool {
                $hits[] = [$idx, $pk];

                return true;
            },
            false,
            false,
            true,
        );

        self::assertTrue($result);
        self::assertCount(3, $hits);
        self::assertSame(MemcachedConstants::RES_SUCCESS, $core->resultCode);
    }

    public function testExecuteKeyedRejectsBadKey(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 0]);

        $executor = new ClientKeyedExecutor(
            $this->env($core),
            new ClientRoutingCoordinator($this->env($core)),
            new ClientHealthRecorder($this->env($core)),
        );

        self::assertFalse($executor->executeKeyed('', null, static fn (): bool => true));
        self::assertSame(MemcachedConstants::RES_BAD_KEY_PROVIDED, $core->resultCode);
    }

    public function testExecuteKeyedReturnsFailureWhenNoServersAreConfigured(): void
    {
        $core = MemcachedClientCore::createFresh();
        $env = $this->env($core);
        $executor = new ClientKeyedExecutor(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        self::assertFalse($executor->executeKeyed(
            'k',
            null,
            static fn (): bool => true,
            false,
            false,
            true,
        ));
        self::assertSame(MemcachedConstants::RES_NO_SERVERS, $core->resultCode);
    }

    public function testExecuteKeyedMapsPrimaryThrowableToFailure(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'solo', 'port' => 11211, 'weight' => 1]);

        $env = $this->env($core);
        $executor = new ClientKeyedExecutor(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        self::assertFalse($executor->executeKeyed(
            'k',
            null,
            static function (): bool {
                throw new \RuntimeException('primary down');
            },
            false,
            false,
            true,
        ));
        self::assertSame(MemcachedConstants::RES_FAILURE, $core->resultCode);
        self::assertSame('primary down', $core->resultMessage);
    }

    public function testExecuteKeyedIgnoresReplicaFailuresAfterPrimarySuccess(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'c', 'port' => 11211, 'weight' => 1]);
        $core->options[MemcachedClient::OPT_NUMBER_OF_REPLICAS] = 2;

        $env = $this->env($core);
        $executor = new ClientKeyedExecutor(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        $calls = 0;
        self::assertTrue($executor->executeKeyed(
            'k',
            null,
            static function () use (&$calls): bool {
                if (1 === ++$calls) {
                    return true;
                }

                throw new \RuntimeException('replica down');
            },
            false,
            false,
            true,
        ));
        self::assertSame(3, $calls);
        self::assertSame(MemcachedConstants::RES_SUCCESS, $core->resultCode);
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
