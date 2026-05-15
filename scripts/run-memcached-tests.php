<?php

declare(strict_types=1);

const MEMCACHED_HOST = '127.0.0.1';
const STARTUP_TIMEOUT_USEC = 5_000_000;
const POLL_INTERVAL_USEC = 50_000;

$memcached = null;
$memcachedPipes = [];

$cleanup = static function () use (&$memcached, &$memcachedPipes): void {
    foreach ($memcachedPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (is_resource($memcached)) {
        proc_terminate($memcached);
        $deadline = microtime(true) + 2.0;
        do {
            $status = proc_get_status($memcached);
            if (!$status['running']) {
                proc_close($memcached);
                $memcached = null;

                return;
            }

            usleep(POLL_INTERVAL_USEC);
        } while (microtime(true) < $deadline);

        proc_terminate($memcached, 9);
        proc_close($memcached);
        $memcached = null;
    }
};

register_shutdown_function($cleanup);

if (function_exists('pcntl_signal')) {
    pcntl_signal(\SIGINT, static function () use ($cleanup): never {
        $cleanup();
        exit(130);
    });
    pcntl_signal(\SIGTERM, static function () use ($cleanup): never {
        $cleanup();
        exit(143);
    });
}

$binary = findMemcachedBinary();
if (null === $binary) {
    fwrite(\STDERR, "memcached binary not found. Install memcached or set MEMCACHED_BINARY.\n");
    exit(127);
}

$port = reserveFreeTcpPort(MEMCACHED_HOST);
$command = [$binary, '-l', MEMCACHED_HOST, '-p', (string) $port, '-U', '0', '-m', '64'];
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
    $command[] = '-u';
    $command[] = 'root';
}

$memcached = proc_open($command, [
    ['file', '/dev/null', 'r'],
    ['pipe', 'w'],
    ['pipe', 'w'],
], $memcachedPipes, __DIR__.'/..');
if (!is_resource($memcached)) {
    fwrite(\STDERR, "Failed to start memcached.\n");
    exit(1);
}

stream_set_blocking($memcachedPipes[1], false);
stream_set_blocking($memcachedPipes[2], false);

if (!waitForTcpServer(MEMCACHED_HOST, $port, STARTUP_TIMEOUT_USEC, $memcached, $memcachedPipes[2])) {
    $stderr = stream_get_contents($memcachedPipes[2]);
    fwrite(\STDERR, 'memcached did not become ready on '.MEMCACHED_HOST.":{$port}.\n");
    if (false !== $stderr && '' !== $stderr) {
        fwrite(\STDERR, $stderr);
    }

    exit(1);
}

fwrite(\STDERR, 'Started memcached on '.MEMCACHED_HOST.":{$port}\n");

$phpunit = __DIR__.'/../vendor/bin/phpunit';
$args = array_slice($argv, 1);
$testTarget = 'tests/Integration/MemcachedIntegrationTest.php';
if (isset($args[0]) && str_starts_with($args[0], 'tests/')) {
    $testTarget = array_shift($args);
}

$testCommand = array_merge(
    phpCommandPrefix($testTarget),
    [
        $phpunit,
        '--configuration=config/phpunit.xml',
        $testTarget,
    ],
    $args,
);

$env = getenv();
if (!is_array($env)) {
    $env = [];
}

$env['MEMCACHED_TEST_HOST'] = MEMCACHED_HOST;
$env['MEMCACHED_TEST_PORT'] = (string) $port;

exit(runProcess($testCommand, __DIR__.'/..', $env));

function findMemcachedBinary(): ?string
{
    $configured = getenv('MEMCACHED_BINARY');
    if (is_string($configured) && '' !== $configured) {
        return is_executable($configured) ? $configured : null;
    }

    $path = getenv('PATH');
    if (!is_string($path)) {
        return null;
    }

    foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
        $candidate = rtrim($dir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.'memcached';
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return non-empty-list<string>
 */
function phpCommandPrefix(string $testTarget): array
{
    $command = [\PHP_BINARY];

    if (str_starts_with($testTarget, 'tests/Integration') || 'tests/Parity' === $testTarget) {
        $command = addOptionalExtension($command, 'igbinary', 'IGBINARY_PECL_EXTENSION');
    }

    if ('tests/Parity' !== $testTarget || extension_loaded('memcached')) {
        return $command;
    }

    return addOptionalExtension($command, 'memcached', 'MEMCACHED_PECL_EXTENSION');
}

/**
 * @param non-empty-list<string> $command
 *
 * @return non-empty-list<string>
 */
function addOptionalExtension(array $command, string $extension, string $envName): array
{
    if (extension_loaded($extension)) {
        return $command;
    }

    $extensionPath = getenv($envName);
    if (!is_string($extensionPath) || '' === $extensionPath) {
        $extensionPath = ini_get('extension_dir').\DIRECTORY_SEPARATOR.$extension.'.so';
    }

    if (is_file($extensionPath)) {
        $command[] = '-d';
        $command[] = 'extension='.$extensionPath;
    }

    return $command;
}

function reserveFreeTcpPort(string $host): int
{
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $server = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if (!is_resource($server)) {
            continue;
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($name)) {
            continue;
        }

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        if ($port > 1024) {
            return $port;
        }
    }

    throw new RuntimeException("Unable to reserve a free TCP port above 1024 for {$host}.");
}

/**
 * @param resource $process
 * @param resource $stderr
 */
function waitForTcpServer(string $host, int $port, int $timeoutUsec, $process, $stderr): bool
{
    $deadline = microtime(true) + ($timeoutUsec / 1_000_000);
    do {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.1);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        $err = stream_get_contents($stderr);
        if (false !== $err && '' !== $err) {
            fwrite(\STDERR, $err);
        }

        usleep(POLL_INTERVAL_USEC);
    } while (microtime(true) < $deadline);

    return false;
}

/**
 * @param list<string>          $command
 * @param array<string, string> $env
 */
function runProcess(array $command, string $cwd, array $env): int
{
    $process = proc_open($command, [
        ['file', '/dev/null', 'r'],
        ['pipe', 'w'],
        ['pipe', 'w'],
    ], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        fwrite(\STDERR, "Failed to start test process.\n");

        return 1;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $exitCode = null;
    while (true) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        drain($pipes[1], \STDOUT);
        drain($pipes[2], \STDERR);

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        usleep(POLL_INTERVAL_USEC);
    }

    drain($pipes[1], \STDOUT);
    drain($pipes[2], \STDERR);

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_close($process);

    return is_int($exitCode) && $exitCode >= 0 ? $exitCode : 1;
}

/**
 * @param resource $input
 * @param resource $output
 */
function drain($input, $output): void
{
    while ('' !== ($chunk = fread($input, 8192)) && false !== $chunk) {
        fwrite($output, $chunk);
    }
}
