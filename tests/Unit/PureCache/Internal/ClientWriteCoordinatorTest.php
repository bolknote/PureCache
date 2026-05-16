<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientHealthRecorder;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Internal\ClientWriteCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

final class ClientWriteCoordinatorTest extends TestCase
{
    public function testRetryStoreOnFailureUsesAlternateServerAfterPrimaryFails(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $core->options[MemcachedClient::OPT_STORE_RETRY_COUNT] = 1;

        $env = $this->env($core);
        $write = new ClientWriteCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        $calls = [];
        self::assertTrue($write->retryStoreOnFailure(
            null,
            'k',
            static function (int $idx) use (&$calls, $core): bool {
                $calls[] = $idx;
                if (1 === \count($calls)) {
                    $core->resultCode = MemcachedConstants::RES_FAILURE;
                    $core->resultMessage = 'primary failed';

                    return false;
                }

                $core->resultCode = MemcachedConstants::RES_SUCCESS;
                $core->resultMessage = '';

                return true;
            },
        ));
        self::assertGreaterThanOrEqual(2, \count($calls));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $core->resultCode);
    }

    public function testRetryStoreRestoresFailureCodeAfterRetryThrowable(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->selector->addServer(['host' => 'a', 'port' => 11211, 'weight' => 1]);
        $core->selector->addServer(['host' => 'b', 'port' => 11211, 'weight' => 1]);
        $core->options[MemcachedClient::OPT_STORE_RETRY_COUNT] = 2;

        $env = $this->env($core);
        $write = new ClientWriteCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        $calls = 0;
        self::assertFalse($write->retryStoreOnFailure(
            null,
            'k',
            static function () use (&$calls, $core): bool {
                ++$calls;
                if (1 === $calls) {
                    $core->resultCode = MemcachedConstants::RES_FAILURE;
                    $core->resultMessage = 'primary failed';

                    return false;
                }

                throw new \RuntimeException('retry down');
            },
        ));
        self::assertGreaterThanOrEqual(2, $calls);
        self::assertSame(MemcachedConstants::RES_FAILURE, $core->resultCode);
        self::assertSame('primary failed', $core->resultMessage);
    }

    public function testWriteFanoutReturnsNoServersWhenPoolIsEmpty(): void
    {
        $core = MemcachedClientCore::createFresh();
        $env = $this->env($core);
        $write = new ClientWriteCoordinator(
            $env,
            new ClientRoutingCoordinator($env),
            new ClientHealthRecorder($env),
        );

        self::assertFalse($write->writeFanout(null, 'k', static fn (): bool => true));
        self::assertSame(MemcachedConstants::RES_NO_SERVERS, $core->resultCode);
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
