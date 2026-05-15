<?php

declare(strict_types=1);

/**
 * Runs PHPUnit under the same PHP binary, optionally preloading ext-igbinary when
 * the shared object exists but is not enabled in php.ini (mirrors run-memcached-tests.php).
 */
$projectRoot = dirname(__DIR__);
chdir($projectRoot);

$command = [\PHP_BINARY];

if (!extension_loaded('igbinary')) {
    $igbinaryPath = getenv('IGBINARY_PECL_EXTENSION');
    if (!is_string($igbinaryPath) || '' === $igbinaryPath) {
        $igbinaryPath = ini_get('extension_dir').\DIRECTORY_SEPARATOR.'igbinary.'.\PHP_SHLIB_SUFFIX;
    }

    if (is_file($igbinaryPath)) {
        $command[] = '-d';
        $command[] = 'extension='.$igbinaryPath;
    }
}

$command[] = $projectRoot.'/vendor/bin/phpunit';
$command = array_merge($command, array_slice($argv, 1));

$process = proc_open($command, [
    ['file', '/dev/null', 'r'],
    ['pipe', 'w'],
    ['pipe', 'w'],
], $pipes, $projectRoot, null, ['bypass_shell' => true]);

if (!is_resource($process)) {
    fwrite(\STDERR, "Failed to start PHPUnit.\n");
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

while (true) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    if (false !== $stdout && '' !== $stdout) {
        fwrite(\STDOUT, $stdout);
    }

    if (false !== $stderr && '' !== $stderr) {
        fwrite(\STDERR, $stderr);
    }

    $status = proc_get_status($process);
    if (!$status['running']) {
        break;
    }

    usleep(50_000);
}

$code = proc_close($process);

exit(0 === $code ? 0 : ($code > 0 ? $code : 1));
