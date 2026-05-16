<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Support;

use PureCache\Tests\Integration\Support\ParallelTestRunner;

/**
 * Starts fake TCP workers under {@code tests/Integration/workers/} for unit tests.
 */
trait FakeWireWorkerTrait
{
    /**
     * @param array<string, string> $env
     *
     * @return resource
     */
    protected function startFakeWireWorker(string $script, array $env)
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $projectRoot = \dirname(__DIR__, 4);
        $workerPath = $projectRoot.'/tests/Integration/workers/'.$script;

        $cmd = array_merge([\PHP_BINARY], ParallelTestRunner::phpExtensionPrefix(), [$workerPath]);
        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $projectRoot,
            $env,
        );

        if (!\is_resource($process)) {
            self::fail('failed to start fake wire server: '.$script);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $ready = false;
        $stdout = '';
        for ($i = 0; $i < 50; ++$i) {
            $chunk = stream_get_contents($pipes[1]);
            if (\is_string($chunk) && '' !== $chunk) {
                $stdout .= $chunk;
            }

            if (str_contains($stdout, 'ready')) {
                $ready = true;
                break;
            }

            usleep(20_000);
        }

        if (!$ready) {
            proc_terminate($process, 9);
            proc_close($process);
            self::fail('fake wire server did not become ready: '.$script);
        }

        return $process;
    }

    protected function reserveEphemeralPort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            self::markTestSkipped('unable to reserve ephemeral port for fake wire server');
        }

        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        if (false === $name || '' === $name) {
            self::markTestSkipped('unable to resolve ephemeral port for fake wire server');
        }

        if (1 !== preg_match('/:(\d+)$/', $name, $matches)) {
            self::markTestSkipped('unable to resolve ephemeral port for fake wire server');
        }

        return (int) $matches[1];
    }

    /**
     * @param resource $process
     */
    protected function stopFakeWireWorker($process): void
    {
        proc_terminate($process, 9);
        proc_close($process);
    }
}
