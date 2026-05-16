<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration\Support;

/**
 * Spawns multiple PHP worker scripts via {@code proc_open} and collects exit codes.
 */
final class ParallelTestRunner
{
    /**
     * @param non-empty-string           $workerScript absolute path to a worker PHP file
     * @param array<string,string>       $baseEnv      environment variables for every worker
     * @param list<array<string,string>> $perWorkerEnv extra env per worker (merged over base)
     *
     * @return list<int> exit codes in worker order
     */
    public static function runWorkers(
        string $workerScript,
        array $baseEnv,
        array $perWorkerEnv,
    ): array {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is required for multi-process integration tests');
        }

        $processes = [];
        $pipesByIndex = [];

        foreach ($perWorkerEnv as $index => $extra) {
            $env = array_merge($baseEnv, $extra);
            $cmd = array_merge(
                [\PHP_BINARY],
                self::phpExtensionPrefix(),
                [$workerScript],
            );

            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptor, $pipes, null, self::workerEnvironment($env));
            if (!\is_resource($process)) {
                self::terminateAll($processes, $pipesByIndex);
                throw new \RuntimeException('failed to start worker '.$index);
            }

            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $processes[$index] = $process;
            $pipesByIndex[$index] = $pipes;
        }

        $exitCodes = [];
        foreach ($processes as $index => $process) {
            $pipes = $pipesByIndex[$index];
            $exitCodes[$index] = self::awaitProcess($process, $pipes);
        }

        return array_values($exitCodes);
    }

    /**
     * @param list<string> $extensionNames
     *
     * @return list<string>
     */
    public static function phpExtensionPrefix(array $extensionNames = ['igbinary']): array
    {
        $prefix = [];
        foreach ($extensionNames as $extension) {
            if (\extension_loaded($extension)) {
                continue;
            }

            $path = getenv(strtoupper($extension).'_PECL_EXTENSION');
            if (!\is_string($path) || '' === $path) {
                $extensionDir = \ini_get('extension_dir');
                $path = (false !== $extensionDir && '' !== $extensionDir ? $extensionDir : '').\DIRECTORY_SEPARATOR.$extension.'.so';
            }

            if (is_file($path)) {
                $prefix[] = '-d';
                $prefix[] = 'extension='.$path;
            }
        }

        return $prefix;
    }

    /**
     * @param array<int, resource>             $processes
     * @param array<int, array<int, resource>> $pipesByIndex
     */
    private static function terminateAll(array $processes, array $pipesByIndex): void
    {
        foreach ($pipesByIndex as $pipes) {
            self::closeWorkerPipes($pipes);
        }

        foreach ($processes as $process) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private static function awaitProcess($process, array $pipes): int
    {
        while (true) {
            $status = proc_get_status($process);
            self::drainPipe($pipes[1]);
            self::drainPipe($pipes[2]);
            if (!$status['running']) {
                self::closeWorkerPipes($pipes);

                proc_close($process);
                $exit = $status['exitcode'];

                return $exit >= 0 ? $exit : 1;
            }

            usleep(20_000);
        }
    }

    /**
     * @param array<int, resource> $pipes
     */
    private static function closeWorkerPipes(array $pipes): void
    {
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
    }

    /**
     * @param resource $pipe
     */
    private static function drainPipe($pipe): void
    {
        while (!feof($pipe)) {
            $chunk = fread($pipe, 65_536);
            if (false === $chunk || '' === $chunk) {
                break;
            }
        }
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function workerEnvironment(array $overrides): array
    {
        return array_merge(self::stringifyEnvironment($_ENV), $overrides);
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<string, string>
     */
    private static function stringifyEnvironment(array $source): array
    {
        $out = [];
        foreach ($source as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }

            if (\is_string($value)) {
                $out[$name] = $value;
            } elseif (is_numeric($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
