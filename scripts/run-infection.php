<?php

declare(strict_types=1);

/**
 * Runs Infection with pre-built PHPUnit coverage and enough memory for cleanup.
 *
 * Parallel mutation runs can leave tens of thousands of directories under
 * {@code cache/infection/}; wiping the tree before each run avoids Symfony
 * Finder exhausting PHP's default 128M limit during teardown. Generating
 * coverage-xml (and the JUnit log Infection expects beside it) once before
 * mutation analysis avoids worker threads reading a partially-written index.
 */
$root = dirname(__DIR__);
$cacheInfection = $root.'/cache/infection';
$coverageXml = $cacheInfection.'/coverage-xml';

if (is_dir($cacheInfection)) {
    passthru('rm -rf '.escapeshellarg($cacheInfection), $wipeStatus);
    if (0 !== $wipeStatus) {
        fwrite(\STDERR, "Failed to wipe {$cacheInfection}\n");
        exit(1);
    }
}

if (!is_dir($coverageXml) && !mkdir($coverageXml, 0o755, true) && !is_dir($coverageXml)) {
    fwrite(\STDERR, "Failed to create {$coverageXml}\n");
    exit(1);
}

$php = \PHP_BINARY;
$errorReporting = (string) (\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED);

$phpunit = $root.'/vendor/bin/phpunit';
$coverageCommand = implode(' ', array_map('escapeshellarg', [
    $php,
    '-d',
    'memory_limit=1G',
    '-d',
    'error_reporting='.$errorReporting,
    $phpunit,
    '--configuration='.$root.'/config/phpunit-coverage.xml',
    '--coverage-xml='.$coverageXml,
    '--log-junit='.$coverageXml.'/junit.xml',
    '--colors=never',
]));

$previousDir = getcwd();
chdir($root);
passthru($coverageCommand, $coverageStatus);
if (false !== $previousDir) {
    chdir($previousDir);
}

if (0 !== $coverageStatus) {
    fwrite(\STDERR, "PHPUnit coverage-xml generation failed with status {$coverageStatus}\n");
    exit(1);
}

if (!is_file($coverageXml.'/index.xml') || !is_file($coverageXml.'/junit.xml')) {
    fwrite(\STDERR, "Missing coverage-xml index or junit log under {$coverageXml}\n");
    exit(1);
}

$infection = $root.'/vendor/bin/infection';
$infectionCommand = implode(' ', array_map('escapeshellarg', [
    $php,
    '-d',
    'memory_limit=1G',
    '-d',
    'error_reporting='.$errorReporting,
    $infection,
    '--configuration='.$root.'/infection.json',
    '--coverage='.$coverageXml,
    '--skip-initial-tests',
    '--no-progress',
    '--threads=4',
]));

chdir($root);
passthru($infectionCommand, $exitCode);
if (false !== $previousDir) {
    chdir($previousDir);
}

exit(is_int($exitCode) && 0 === $exitCode ? 0 : 1);
