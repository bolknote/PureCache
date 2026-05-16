<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\CacheClient;
use PureCache\Memcached\MemcachedClient;
use PureCache\Tests\Integration\Support\ParallelTestRunner;

/**
 * Spawns multiple PHP worker processes via {@see ParallelTestRunner} to exercise
 * real cross-process CAS races and increment contention.
 */
trait MultiProcessConcurrencyTrait
{
    abstract protected static function integrationHost(): string;

    abstract protected static function integrationPort(): int;

    abstract protected function createClient(): CacheClient;

    abstract protected function parallelBackendName(): string;

    protected function assertMultiProcessCasRaceExactlyOneWinner(int $workers = 12): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $key = 'pure_mp_cas_'.bin2hex(random_bytes(8));
        $seed = $this->createClient();
        self::assertTrue($seed->set($key, 'seed', 60));

        $reader = $this->createClient();
        $ext = $reader->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        self::assertIsInt($ext['cas']);
        $cas = (string) $ext['cas'];

        $baseEnv = [
            'PURECACHE_BACKEND' => $this->parallelBackendName(),
            'PURECACHE_HOST' => static::integrationHost(),
            'PURECACHE_PORT' => (string) static::integrationPort(),
            'PURECACHE_KEY' => $key,
            'PURECACHE_CAS' => $cas,
        ];

        $perWorker = [];
        for ($i = 0; $i < $workers; ++$i) {
            $perWorker[] = ['PURECACHE_WORKER_ID' => (string) $i];
        }

        $script = __DIR__.'/workers/cas_race_worker.php';
        $exitCodes = ParallelTestRunner::runWorkers($script, $baseEnv, $perWorker);

        $wins = 0;
        foreach ($exitCodes as $code) {
            if (0 === $code) {
                ++$wins;
            }
        }

        self::assertSame(1, $wins, 'exactly one multi-process CAS worker may succeed');

        $seed->delete($key);
    }

    protected function assertMultiProcessIncrementStorm(int $workers = 8, int $roundsPerWorker = 10): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $key = 'pure_mp_incr_'.bin2hex(random_bytes(8));
        $seed = $this->createClient();
        self::assertTrue($seed->set($key, 0, 60));

        $baseEnv = [
            'PURECACHE_BACKEND' => $this->parallelBackendName(),
            'PURECACHE_HOST' => static::integrationHost(),
            'PURECACHE_PORT' => (string) static::integrationPort(),
            'PURECACHE_KEY' => $key,
            'PURECACHE_ROUNDS' => (string) $roundsPerWorker,
        ];

        $perWorker = array_fill(0, $workers, []);

        $script = __DIR__.'/workers/incr_storm_worker.php';
        $exitCodes = ParallelTestRunner::runWorkers($script, $baseEnv, $perWorker);

        foreach ($exitCodes as $code) {
            self::assertSame(0, $code, 'every increment worker must exit 0');
        }

        self::assertSame($workers * $roundsPerWorker, $seed->get($key));

        $seed->delete($key);
    }
}
