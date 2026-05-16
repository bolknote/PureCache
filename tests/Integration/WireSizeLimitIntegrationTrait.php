<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;
use PureCache\Tests\Integration\Support\ParallelTestRunner;

/**
 * Fake TCP peers that declare oversized payloads on the wire (meta VA, RESP bulk,
 * Ignite frame length) so read limits are enforced before large allocations.
 */
trait WireSizeLimitIntegrationTrait
{
    protected function assertWireOversizedVaIsRejected(int $declaredBytes = 200, int $readLimit = 64): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            self::markTestSkipped('unable to reserve ephemeral port for fake meta server');
        }

        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        if (false === $name || '' === $name) {
            self::markTestSkipped('unable to resolve ephemeral port for fake meta server');
        }

        if (1 !== preg_match('/:(\d+)$/', $name, $matches)) {
            self::markTestSkipped('unable to resolve ephemeral port for fake meta server');
        }

        $port = (int) $matches[1];

        $script = __DIR__.'/workers/fake_meta_oversized_va_server.php';
        $cmd = array_merge([\PHP_BINARY], ParallelTestRunner::phpExtensionPrefix(), [$script]);

        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            [
                'FAKE_META_PORT' => (string) $port,
                'FAKE_META_VA_SIZE' => (string) $declaredBytes,
            ],
        );

        if (!\is_resource($process)) {
            self::fail('failed to start fake meta server');
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
            self::fail('fake meta server did not become ready');
        }

        $memcached = new MemcachedClient();
        $memcached->addServer('127.0.0.1', $port);
        $memcached->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, $readLimit);

        self::assertFalse($memcached->get('wire_trap_key'));
        self::assertSame(MemcachedConstants::RES_E2BIG, $memcached->getResultCode());

        proc_terminate($process, 9);
        proc_close($process);
    }

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

        $cmd = array_merge([\PHP_BINARY], ParallelTestRunner::phpExtensionPrefix(), [$script]);
        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
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
}
