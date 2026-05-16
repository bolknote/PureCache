<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Tests\Integration\Support\ParallelTestRunner;

/**
 * Spawns parallel session workers to verify memcached session lock exclusivity.
 */
trait SessionLockConcurrencyTrait
{
    abstract protected static function integrationHost(): string;

    abstract protected static function integrationPort(): int;

    protected function assertMultiProcessSessionLockExclusive(int $workers = 8): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $sessionId = 'pure_mp_sess_'.bin2hex(random_bytes(12));
        $savePath = static::integrationHost().':'.static::integrationPort();

        $baseEnv = [
            'PURECACHE_HOST' => static::integrationHost(),
            'PURECACHE_PORT' => (string) static::integrationPort(),
            'PURECACHE_SESSION_SAVE_PATH' => $savePath,
            'PURECACHE_SESSION_ID' => $sessionId,
        ];

        $perWorker = [];
        for ($i = 0; $i < $workers; ++$i) {
            $perWorker[] = ['PURECACHE_WORKER_ID' => (string) $i];
        }

        $script = __DIR__.'/workers/session_lock_worker.php';
        $exitCodes = ParallelTestRunner::runWorkers($script, $baseEnv, $perWorker);

        $acquired = 0;
        foreach ($exitCodes as $code) {
            if (0 === $code) {
                ++$acquired;
            }
        }

        self::assertSame(1, $acquired, 'exactly one session worker may acquire the memcached session lock');
    }
}
