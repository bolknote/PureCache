<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientDelayedFetchCoordinator;
use PureCache\Internal\ClientRoutingCoordinator;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;

final class ClientDelayedFetchCoordinatorTest extends TestCase
{
    private MemcachedClientCore $core;

    #[\Override]
    protected function setUp(): void
    {
        $this->core = MemcachedClientCore::createFresh();
    }

    public function testFetchOneWithoutQueueSetsFetchNotFinished(): void
    {
        $coordinator = $this->coordinator();

        self::assertFalse($coordinator->fetchOne());
        self::assertSame(MemcachedConstants::RES_FETCH_NOTFINISHED, $this->core->resultCode);
    }

    public function testAbortDelayedFetchClearsQueueAndCursor(): void
    {
        $coordinator = $this->coordinator();
        $this->core->delayedQueue = [['keys' => ['a'], 'serverKey' => null, 'withCas' => false]];
        $this->core->delayedResults = [['key' => 'a', 'value' => 1]];
        $this->core->delayedCursor = 2;

        $coordinator->abortDelayedFetch();

        self::assertSame([], $this->core->delayedQueue);
    }

    public function testFetchOneReturnsRowsAndEndsBatch(): void
    {
        $coordinator = $this->coordinator(
            doFetchBatch: static fn (): array => [
                ['key' => 'a', 'value' => '1'],
                ['key' => 'b', 'value' => '2'],
            ],
        );
        $this->core->delayedQueue = [['keys' => ['a', 'b'], 'serverKey' => null, 'withCas' => false]];

        self::assertSame(['key' => 'a', 'value' => '1'], $coordinator->fetchOne());
        self::assertSame(MemcachedConstants::RES_SUCCESS, $this->core->resultCode);
        self::assertSame(['key' => 'b', 'value' => '2'], $coordinator->fetchOne());
        self::assertFalse($coordinator->fetchOne());
    }

    public function testFetchOneAbortsWhenBatchPrimingFails(): void
    {
        $coordinator = $this->coordinator(
            doFetchBatch: static fn (): false => false,
        );
        $this->core->delayedQueue = [['keys' => ['a'], 'serverKey' => null, 'withCas' => false]];

        self::assertFalse($coordinator->fetchOne());
        self::assertSame([], $this->core->delayedQueue);
        self::assertNull($this->core->delayedResults);
    }

    public function testFetchAllMergesMultipleQueuedBatches(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(
            doFetchBatch: static function () use (&$calls): array {
                return match (++$calls) {
                    1 => [['key' => 'a', 'value' => 1]],
                    2 => [['key' => 'b', 'value' => 2]],
                    default => [],
                };
            },
        );
        $this->core->delayedQueue = [
            ['keys' => ['a'], 'serverKey' => null, 'withCas' => false],
            ['keys' => ['b'], 'serverKey' => null, 'withCas' => false],
        ];

        $all = $coordinator->fetchAll();

        self::assertIsArray($all);
        self::assertCount(2, $all);
        self::assertSame(MemcachedConstants::RES_SUCCESS, $this->core->resultCode);
    }

    public function testEnqueueDelayedRejectsInvalidServerKey(): void
    {
        $coordinator = $this->coordinator(checkKey: static fn (string $key): bool => 'bad key' !== $key);

        self::assertFalse($coordinator->enqueueDelayed('bad key', ['item'], false, null));
        self::assertSame(MemcachedConstants::RES_BAD_KEY_PROVIDED, $this->core->resultCode);
    }

    public function testEnqueueDelayedAcceptsEmptyKeyList(): void
    {
        $coordinator = $this->coordinator();

        self::assertTrue($coordinator->enqueueDelayed(null, [], false, null));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $this->core->resultCode);
    }

    public function testEnqueueDelayedWithValueCallbackDelegates(): void
    {
        $coordinator = $this->coordinator(
            doGetDelayedValueCallback: static fn (): bool => true,
        );

        self::assertTrue($coordinator->enqueueDelayed(null, ['a'], false, static function (): void {}));
    }

    public function testFetchOneRePrimesNextBatchWhenCursorExhausted(): void
    {
        $coordinator = $this->coordinator(
            doFetchBatch: static fn (): array => [['key' => 'b', 'value' => 2]],
        );
        $this->core->delayedQueue = [
            ['keys' => ['b'], 'serverKey' => null, 'withCas' => false],
        ];
        $this->core->delayedResults = [['key' => 'a', 'value' => 1]];
        $this->core->delayedCursor = 1;

        self::assertSame(['key' => 'b', 'value' => 2], $coordinator->fetchOne());
    }

    public function testPullDelayedResultsBatchMarksEmptyWhenQueueIsDrained(): void
    {
        $coordinator = $this->coordinator();
        $this->core->delayedQueue = [];
        $this->core->delayedResults = null;

        self::assertSame([], $coordinator->pullDelayedResultsBatch());
    }

    public function testFetchOneAbortsWhenRePrimeFailsAfterCursorOverflow(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(
            doFetchBatch: static function () use (&$calls): false|array {
                return 0 === $calls++ ? [['key' => 'a', 'value' => 1]] : false;
            },
        );
        $this->core->delayedQueue = [
            ['keys' => ['a'], 'serverKey' => null, 'withCas' => false],
            ['keys' => ['b'], 'serverKey' => null, 'withCas' => false],
        ];
        $this->core->delayedResults = null;
        $this->core->delayedCursor = 0;

        self::assertSame(['key' => 'a', 'value' => 1], $coordinator->fetchOne());
        self::assertFalse($coordinator->fetchOne());
        self::assertSame([], $this->core->delayedQueue);
    }

    public function testFetchOneAbortsWhenServersUnavailableDuringPrime(): void
    {
        $coordinator = $this->coordinator(
            doFetchBatch: static fn (): array => [['key' => 'a', 'value' => 1]],
        );
        $this->core->selector->reset();
        $this->core->delayedQueue = [['keys' => ['a'], 'serverKey' => null, 'withCas' => false]];

        self::assertFalse($coordinator->fetchOne());
        self::assertSame([], $this->core->delayedQueue);
    }

    public function testFetchAllFailsWhenSecondBatchPrimingFails(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(
            doFetchBatch: static function () use (&$calls): false|array {
                return 0 === $calls++ ? [['key' => 'a', 'value' => 1]] : false;
            },
        );
        $this->core->delayedQueue = [
            ['keys' => ['a'], 'serverKey' => null, 'withCas' => false],
            ['keys' => ['b'], 'serverKey' => null, 'withCas' => false],
        ];

        self::assertFalse($coordinator->fetchAll());
        self::assertSame([], $this->core->delayedQueue);
    }

    /**
     * @param \Closure(list<string>, ?string, bool): (list<array<string, mixed>>|false)|null $doFetchBatch
     * @param \Closure(string): bool|null                                                    $checkKey
     * @param \Closure(list<string>, ?string, bool, callable): bool|null                     $doGetDelayedValueCallback
     */
    private function coordinator(
        ?\Closure $doFetchBatch = null,
        ?\Closure $checkKey = null,
        ?\Closure $doGetDelayedValueCallback = null,
    ): ClientDelayedFetchCoordinator {
        $this->core = MemcachedClientCore::createFresh();
        $this->core->selector->addServer(['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0]);

        $core = $this->core;

        $env = new ClientCoordinatorEnv(
            $core,
            static function (int $code, ?string $message = null) use ($core): void {
                $core->resultCode = $code;
                $core->resultMessage = $message ?? '';
            },
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => $core->optionInt($option, $default),
            static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
            static fn (string $key): string => 'pfx:'.$key,
            static fn (string $key): string => $key,
            $checkKey ?? static fn (string $_key): bool => true,
        );

        $routing = new ClientRoutingCoordinator($env);

        return new ClientDelayedFetchCoordinator(
            $env,
            $routing,
            static function (): void {},
            static fn (mixed $k): string => \is_scalar($k) ? (string) $k : '',
            static fn (array $keys): array => array_values(array_map(
                static fn (mixed $key): string => \is_scalar($key) ? (string) $key : '',
                $keys,
            )),
            $doGetDelayedValueCallback ?? static fn (): bool => true,
            $doFetchBatch ?? static fn (): array => [],
        );
    }
}
