<?php

declare(strict_types=1);

const IGNITE_BIND_HOST = '127.0.0.1';
const IGNITE_DEFAULT_VERSION = '2.16.0';
const IGNITE_STARTUP_TIMEOUT_USEC = 120_000_000;
const IGNITE_SHUTDOWN_TIMEOUT_SEC = 10.0;
const POLL_INTERVAL_USEC = 100_000;

$cacheDir = realpath(__DIR__.'/..').'/cache/ignite';
$igniteInstance = null;

$cleanup = static function () use (&$igniteInstance): void {
    if (null === $igniteInstance) {
        return;
    }

    stopIgniteServer($igniteInstance['process'], $igniteInstance['pipes']);
    $igniteInstance = null;
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

$envHost = getenv('IGNITE_TEST_HOST');
$envPort = getenv('IGNITE_TEST_PORT');

if (is_string($envHost) && '' !== $envHost && is_string($envPort) && '' !== $envPort) {
    $host = $envHost;
    $port = (int) $envPort;
    if (!waitForTcpServer($host, $port, IGNITE_STARTUP_TIMEOUT_USEC)) {
        fwrite(\STDERR, "External Apache Ignite is not reachable at {$host}:{$port}.\n");
        exit(1);
    }

    fwrite(\STDERR, "Using external Apache Ignite at {$host}:{$port}\n");
} else {
    if (!isJavaAvailable()) {
        fwrite(\STDERR, "java not found on PATH; Apache Ignite needs a JDK 11+ runtime.\nInstall Java or set IGNITE_TEST_HOST/IGNITE_TEST_PORT to point at an existing thin-client endpoint.\n");
        exit(127);
    }

    $version = (string) (getenv('IGNITE_VERSION') ?: IGNITE_DEFAULT_VERSION);
    try {
        $binary = ensureIgniteBinary($cacheDir, $version);
    } catch (Throwable $throwable) {
        fwrite(\STDERR, "Unable to provision Apache Ignite {$version}: {$throwable->getMessage()}\nSet IGNITE_HOME or IGNITE_TEST_HOST/IGNITE_TEST_PORT, or run docker compose up -d ignite.\n");
        exit(127);
    }

    try {
        $port = reserveFreeTcpPort(IGNITE_BIND_HOST);
        $configPath = writeIgniteConfig($cacheDir, IGNITE_BIND_HOST, $port);
        $igniteInstance = startIgniteServer($binary, $configPath, $cacheDir);
        $host = IGNITE_BIND_HOST;

        if (!waitForTcpServer($host, $port, IGNITE_STARTUP_TIMEOUT_USEC, $igniteInstance['process'])) {
            fwrite(\STDERR, "Apache Ignite did not become ready on {$host}:{$port} within ".(IGNITE_STARTUP_TIMEOUT_USEC / 1_000_000)."s.\n");
            $log = @file_get_contents($igniteInstance['logFile']);
            if (is_string($log) && '' !== $log) {
                fwrite(\STDERR, $log);
            }

            $cleanup();
            exit(1);
        }

        fwrite(\STDERR, "Started Apache Ignite on {$host}:{$port}\n");
    } catch (Throwable $throwable) {
        fwrite(\STDERR, "Failed to start Apache Ignite: {$throwable->getMessage()}\n");
        $cleanup();
        exit(1);
    }
}

$phpunit = __DIR__.'/../vendor/bin/phpunit';
$args = array_slice($argv, 1);
$testTarget = 'tests/Integration/IgniteIntegrationTest.php';
if (isset($args[0]) && str_starts_with($args[0], 'tests/')) {
    $testTarget = array_shift($args);
}

$testCommand = array_merge(
    [\PHP_BINARY],
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

$env['IGNITE_TEST_HOST'] = $host;
$env['IGNITE_TEST_PORT'] = (string) $port;

exit(runProcess($testCommand, __DIR__.'/..', $env));

function ensureIgniteBinary(string $cacheDir, string $version): string
{
    $configured = getenv('IGNITE_HOME');
    if (is_string($configured) && '' !== $configured) {
        $candidate = rtrim($configured, '/').'/bin/ignite.sh';
        if (is_executable($candidate)) {
            return $candidate;
        }

        throw new RuntimeException("IGNITE_HOME is set but {$candidate} is not executable.");
    }

    $extracted = $cacheDir.'/apache-ignite-'.$version.'-bin';
    $candidate = $extracted.'/bin/ignite.sh';
    if (is_executable($candidate)) {
        return $candidate;
    }

    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
        throw new RuntimeException("Cannot create cache directory {$cacheDir}.");
    }

    $archive = $cacheDir.'/apache-ignite-'.$version.'-bin.zip';
    if (!is_file($archive)) {
        downloadIgniteArchive($archive, $version);
    }

    extractIgniteArchive($archive, $cacheDir);
    @chmod($candidate, 0o755);
    if (!is_executable($candidate)) {
        throw new RuntimeException("Extraction completed but {$candidate} is not executable.");
    }

    return $candidate;
}

function downloadIgniteArchive(string $destination, string $version): void
{
    // archive.apache.org keeps all historical releases; dlcdn only mirrors the latest
    // versions and would silently 404 for archived ones, which previously corrupted
    // resumed downloads by writing the 404 body into the .part file.
    $mirrors = [
        "https://archive.apache.org/dist/ignite/{$version}/apache-ignite-{$version}-bin.zip",
        "https://dlcdn.apache.org/ignite/{$version}/apache-ignite-{$version}-bin.zip",
    ];

    $tmp = $destination.'.part';
    foreach ($mirrors as $url) {
        fwrite(\STDERR, "Downloading {$url}...\n");
        $status = passthroughCommand([
            'curl',
            '-fL',
            '--retry', '5',
            '--retry-delay', '2',
            '--retry-all-errors',
            '--connect-timeout', '15',
            '--continue-at', '-',
            '--progress-bar',
            '-o', $tmp,
            $url,
        ]);
        if (0 === $status && is_file($tmp) && filesize($tmp) > 1_000_000 && isValidZip($tmp)) {
            rename($tmp, $destination);

            return;
        }

        @unlink($tmp);
        fwrite(\STDERR, "Mirror failed with status {$status}; trying next...\n");
    }

    throw new RuntimeException('all Apache Ignite mirrors failed.');
}

function isValidZip(string $path): bool
{
    $unzip = findExecutable('unzip');
    if (null === $unzip) {
        return true;
    }

    return 0 === passthroughCommand([$unzip, '-tq', $path]);
}

function extractIgniteArchive(string $archive, string $cacheDir): void
{
    $unzip = findExecutable('unzip');
    if (null === $unzip) {
        throw new RuntimeException('unzip binary not found on PATH.');
    }

    $status = passthroughCommand([$unzip, '-q', '-o', $archive, '-d', $cacheDir]);
    if (0 !== $status) {
        throw new RuntimeException('unzip failed with status '.$status);
    }
}

function writeIgniteLoggingConfig(string $cacheDir): string
{
    $path = $cacheDir.'/logging.properties';
    $contents = "handlers=\nhandlers=java.util.logging.ConsoleHandler\n".
        "java.util.logging.ConsoleHandler.level=SEVERE\n".
        "java.util.logging.ConsoleHandler.formatter=java.util.logging.SimpleFormatter\n".
        ".level=SEVERE\norg.apache.ignite.level=SEVERE\n";
    if (false === file_put_contents($path, $contents)) {
        throw new RuntimeException("Cannot write logging.properties to {$path}.");
    }

    return $path;
}

function writeIgniteConfig(string $cacheDir, string $host, int $port): string
{
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
        throw new RuntimeException("Cannot create cache directory {$cacheDir}.");
    }

    $configPath = $cacheDir.'/ignite-test-'.$port.'.xml';
    $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <beans xmlns="http://www.springframework.org/schema/beans"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xsi:schemaLocation="http://www.springframework.org/schema/beans http://www.springframework.org/schema/beans/spring-beans.xsd">
            <bean id="ignite.cfg" class="org.apache.ignite.configuration.IgniteConfiguration">
                <property name="gridLogger">
                    <bean class="org.apache.ignite.logger.java.JavaLogger"/>
                </property>
                <property name="metricsLogFrequency" value="0"/>
                <property name="clientConnectorConfiguration">
                    <bean class="org.apache.ignite.configuration.ClientConnectorConfiguration">
                        <property name="host" value="{$host}"/>
                        <property name="port" value="{$port}"/>
                        <property name="portRange" value="0"/>
                    </bean>
                </property>
                <property name="discoverySpi">
                    <bean class="org.apache.ignite.spi.discovery.tcp.TcpDiscoverySpi">
                        <property name="localAddress" value="{$host}"/>
                        <property name="ipFinder">
                            <bean class="org.apache.ignite.spi.discovery.tcp.ipfinder.vm.TcpDiscoveryVmIpFinder">
                                <property name="addresses">
                                    <list>
                                        <value>{$host}:47500..47509</value>
                                    </list>
                                </property>
                            </bean>
                        </property>
                    </bean>
                </property>
                <property name="communicationSpi">
                    <bean class="org.apache.ignite.spi.communication.tcp.TcpCommunicationSpi">
                        <property name="localAddress" value="{$host}"/>
                    </bean>
                </property>
            </bean>
        </beans>
        XML;

    if (false === file_put_contents($configPath, $xml)) {
        throw new RuntimeException("Cannot write Ignite config to {$configPath}.");
    }

    return $configPath;
}

/**
 * @return array{process: resource, pipes: array<int, resource>}
 */
function startIgniteServer(string $binary, string $configPath, string $cacheDir): array
{
    $igniteHome = dirname($binary, 2);
    $loggingConfig = writeIgniteLoggingConfig($cacheDir);
    $logFile = $cacheDir.'/ignite-server.log';
    @unlink($logFile);

    $jvmOpts = implode(' ', [
        '-Xms256m',
        '-Xmx512m',
        '-DIGNITE_QUIET=true',
        '-DIGNITE_UPDATE_NOTIFIER=false',
        '-DIGNITE_NO_ASCII=true',
        '-DIGNITE_PERFORMANCE_SUGGESTIONS_DISABLED=true',
        '-DIGNITE_WORK_DIR='.escapeshellarg($cacheDir.'/work'),
        '-Djava.util.logging.config.file='.escapeshellarg($loggingConfig),
        '--add-opens=java.base/jdk.internal.access=ALL-UNNAMED',
        '--add-opens=java.base/jdk.internal.misc=ALL-UNNAMED',
        '--add-opens=java.base/sun.nio.ch=ALL-UNNAMED',
        '--add-opens=java.base/sun.util.calendar=ALL-UNNAMED',
        '--add-opens=java.base/java.io=ALL-UNNAMED',
        '--add-opens=java.base/java.nio=ALL-UNNAMED',
        '--add-opens=java.base/java.net=ALL-UNNAMED',
        '--add-opens=java.base/java.util=ALL-UNNAMED',
        '--add-opens=java.base/java.util.concurrent=ALL-UNNAMED',
        '--add-opens=java.base/java.util.concurrent.atomic=ALL-UNNAMED',
        '--add-opens=java.base/java.util.concurrent.locks=ALL-UNNAMED',
        '--add-opens=java.base/java.lang=ALL-UNNAMED',
        '--add-opens=java.base/java.lang.invoke=ALL-UNNAMED',
        '--add-opens=java.base/java.math=ALL-UNNAMED',
        '--add-opens=java.base/java.text=ALL-UNNAMED',
        '--add-opens=java.base/java.time=ALL-UNNAMED',
        '--add-opens=java.management/sun.management=ALL-UNNAMED',
        '--add-opens=jdk.management/com.sun.management.internal=ALL-UNNAMED',
    ]);

    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }

    $env['IGNITE_HOME'] = $igniteHome;
    $env['JVM_OPTS'] = trim(($env['JVM_OPTS'] ?? '').' '.$jvmOpts);

    $bash = findExecutable('bash') ?? '/bin/bash';

    $pipes = [];
    $process = proc_open(
        [$bash, $binary, $configPath],
        [
            ['file', '/dev/null', 'r'],
            ['file', $logFile, 'w'],
            ['file', $logFile, 'a'],
        ],
        $pipes,
        $igniteHome,
        $env,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('proc_open(ignite.sh) failed.');
    }

    return ['process' => $process, 'pipes' => $pipes, 'logFile' => $logFile];
}

/**
 * @param resource             $process
 * @param array<int, resource> $pipes
 */
function stopIgniteServer($process, array $pipes): void
{
    if (is_resource($process)) {
        // ignite.sh does NOT exec the JVM; it runs `"$JAVA" ... "$@"` inside a `while`
        // loop, so killing only the wrapper would leave the actual Apache Ignite JVM
        // as an orphan TCP listener. Walk the full descendant tree and signal every
        // child before terminating the wrapper.
        $status = proc_get_status($process);
        $wrapperPid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        if ($wrapperPid > 0) {
            signalProcessTree($wrapperPid, \SIGTERM);
        }

        proc_terminate($process);

        $deadline = microtime(true) + IGNITE_SHUTDOWN_TIMEOUT_SEC;
        do {
            $status = proc_get_status($process);
            if (!$status['running'] && ($wrapperPid <= 0 || !processTreeHasSurvivors($wrapperPid))) {
                break;
            }

            usleep(POLL_INTERVAL_USEC);
        } while (microtime(true) < $deadline);

        if ($wrapperPid > 0 && processTreeHasSurvivors($wrapperPid)) {
            signalProcessTree($wrapperPid, \SIGKILL);
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }

        proc_close($process);
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

/**
 * @return list<int>
 */
function collectDescendantPids(int $rootPid): array
{
    $queue = [$rootPid];
    $seen = [];
    $descendants = [];
    while ([] !== $queue) {
        $pid = array_shift($queue);
        if (isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;

        $output = [];
        $status = -1;
        @exec('pgrep -P '.escapeshellarg((string) $pid).' 2>/dev/null', $output, $status);
        foreach ($output as $line) {
            $child = (int) trim($line);
            if ($child > 0 && $child !== $pid) {
                $descendants[] = $child;
                $queue[] = $child;
            }
        }
    }

    return array_reverse($descendants);
}

function signalProcessTree(int $rootPid, int $signal): void
{
    $pids = collectDescendantPids($rootPid);
    $pids[] = $rootPid;
    foreach ($pids as $pid) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            @passthroughCommand(['kill', '-'.$signal, (string) $pid]);
        }
    }
}

function processTreeHasSurvivors(int $rootPid): bool
{
    foreach (collectDescendantPids($rootPid) as $pid) {
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }
        } elseif (0 === passthroughCommand(['kill', '-0', (string) $pid])) {
            return true;
        }
    }

    return false;
}

function isJavaAvailable(): bool
{
    return null !== findExecutable('java');
}

function findExecutable(string $name): ?string
{
    $path = getenv('PATH');
    if (is_string($path)) {
        foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.$name;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
    }

    foreach (['/usr/bin/'.$name, '/usr/local/bin/'.$name, '/opt/homebrew/bin/'.$name] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
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
 * @param resource|null $process
 * @param resource|null $stderr
 */
function waitForTcpServer(string $host, int $port, int $timeoutUsec, $process = null, $stderr = null): bool
{
    $deadline = microtime(true) + ($timeoutUsec / 1_000_000);
    do {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if (is_resource($process)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return false;
            }
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        if (is_resource($stderr)) {
            $chunk = stream_get_contents($stderr);
            if (is_string($chunk) && '' !== $chunk) {
                fwrite(\STDERR, $chunk);
            }
        }

        usleep(POLL_INTERVAL_USEC);
    } while (microtime(true) < $deadline);

    return false;
}

/**
 * @param list<string> $command
 */
function passthroughCommand(array $command): int
{
    $process = proc_open($command, [
        ['file', '/dev/null', 'r'],
        \STDOUT,
        \STDERR,
    ], $pipes);
    if (!is_resource($process)) {
        return 1;
    }

    $exitCode = proc_close($process);

    return is_int($exitCode) ? $exitCode : 1;
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
