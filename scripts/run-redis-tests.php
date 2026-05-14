<?php

declare(strict_types=1);

const REDIS_BIND_HOST = '127.0.0.1';
const STARTUP_TIMEOUT_USEC = 5_000_000;
const POLL_INTERVAL_USEC = 50_000;

$redisInstances = [];

$cleanup = static function () use (&$redisInstances): void {
    for ($i = count($redisInstances) - 1; $i >= 0; --$i) {
        stopRedisServer($redisInstances[$i]['process'], $redisInstances[$i]['pipes']);
    }

    $redisInstances = [];
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

$externalHost = getenv('REDIS_TEST_HOST');
$externalPrimaryPort = getenv('REDIS_TEST_PORT');

if (is_string($externalHost) && '' !== $externalHost && is_string($externalPrimaryPort) && '' !== $externalPrimaryPort) {
    fwrite(\STDERR, 'Using external redis at '.$externalHost.':'.$externalPrimaryPort."\n");
    $primaryRedis = ['process' => null, 'pipes' => [], 'port' => (int) $externalPrimaryPort, 'host' => $externalHost];
    $externalSecondaryPort = getenv('REDIS_TEST_PORT_2');
    if (is_string($externalSecondaryPort) && '' !== $externalSecondaryPort) {
        $secondaryRedis = ['process' => null, 'pipes' => [], 'port' => (int) $externalSecondaryPort, 'host' => $externalHost];
    } else {
        $secondaryRedis = $primaryRedis;
    }
} else {
    $binary = findRedisServerBinary();
    if (null === $binary) {
        fwrite(\STDERR, "redis-server binary not found. Install Redis or set REDIS_BINARY (or point REDIS_TEST_HOST + REDIS_TEST_PORT at an existing server).\n");
        exit(127);
    }

    try {
        $primaryRedis = startRedisServer($binary, REDIS_BIND_HOST);
        $primaryRedis['host'] = REDIS_BIND_HOST;
        $redisInstances[] = $primaryRedis;
        fwrite(\STDERR, 'Started redis-server (primary) on '.REDIS_BIND_HOST.':'.$primaryRedis['port']."\n");

        $secondaryRedis = startRedisServer($binary, REDIS_BIND_HOST);
        $secondaryRedis['host'] = REDIS_BIND_HOST;
        $redisInstances[] = $secondaryRedis;
        fwrite(\STDERR, 'Started redis-server (secondary) on '.REDIS_BIND_HOST.':'.$secondaryRedis['port']."\n");
    } catch (Throwable $throwable) {
        fwrite(\STDERR, "Failed to start redis-server for integration tests: {$throwable->getMessage()}\n");
        $cleanup();
        exit(1);
    }
}

$phpunit = __DIR__.'/../vendor/bin/phpunit';
$args = array_slice($argv, 1);
$testTarget = 'tests/Integration/RedisIntegrationTest.php';
if (isset($args[0]) && str_starts_with($args[0], 'tests/')) {
    $testTarget = array_shift($args);
}

$testCommand = array_merge(
    phpCommandPrefix(),
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

$env['REDIS_TEST_HOST'] = $primaryRedis['host'] ?? REDIS_BIND_HOST;
$env['REDIS_TEST_PORT'] = (string) $primaryRedis['port'];
$env['REDIS_TEST_PORT_2'] = (string) $secondaryRedis['port'];

exit(runProcess($testCommand, __DIR__.'/..', $env));

function findRedisServerBinary(): ?string
{
    $configured = getenv('REDIS_BINARY');
    if (is_string($configured) && '' !== $configured) {
        return is_executable($configured) ? $configured : null;
    }

    $path = getenv('PATH');
    if (!is_string($path)) {
        return null;
    }

    foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
        $candidate = rtrim($dir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.'redis-server';
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    foreach (['/opt/homebrew/bin/redis-server', '/usr/local/bin/redis-server'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return non-empty-list<string>
 */
function phpCommandPrefix(): array
{
    $command = [\PHP_BINARY];

    return addOptionalExtension($command, 'igbinary', 'IGBINARY_PECL_EXTENSION');
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
 * @return array{process: resource, pipes: array<int, resource>, port: int}
 */
function startRedisServer(string $binary, string $host): array
{
    $port = reserveFreeTcpPort($host);
    $command = [
        $binary,
        '--bind', $host,
        '--port', (string) $port,
        '--save', '',
        '--appendonly', 'no',
        '--loglevel', 'warning',
    ];

    $pipes = [];
    $process = proc_open($command, [
        ['file', '/dev/null', 'r'],
        ['pipe', 'w'],
        ['pipe', 'w'],
    ], $pipes, __DIR__.'/..');
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to start redis-server on {$host}:{$port}");
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    if (!waitForTcpServer($host, $port, STARTUP_TIMEOUT_USEC, $process, $pipes[2])) {
        $stderr = stream_get_contents($pipes[2]);
        stopRedisServer($process, $pipes);
        $details = false !== $stderr && '' !== $stderr ? trim($stderr) : 'no stderr';
        throw new RuntimeException("redis-server did not become ready on {$host}:{$port} ({$details})");
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => $port,
    ];
}

/**
 * @param resource             $process
 * @param array<int, resource> $pipes
 */
function stopRedisServer($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (!is_resource($process)) {
        return;
    }

    proc_terminate($process);
    $deadline = microtime(true) + 2.0;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            proc_close($process);

            return;
        }

        usleep(POLL_INTERVAL_USEC);
    } while (microtime(true) < $deadline);

    proc_terminate($process, 9);
    proc_close($process);
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
