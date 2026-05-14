<?php

declare(strict_types=1);

/**
 * Re-runs the unit suite with every PureCache opt-extension function
 * neutered via {@code disable_functions=...}. The companion of
 * {@see run-phpunit.php}: the regular run exercises the "has igbinary /
 * msgpack / zstd / fastlz" branches; this one exercises the negative
 * branches that {@see ValueCodec} and {@see ValueCodecTest} skip whenever
 * the real extensions are loaded.
 *
 * We rely on PHP 8+ semantics: when a function is listed in
 * {@code disable_functions}, {@code function_exists()} returns
 * {@code false} for it. So {@see ValueCodec} takes the "ext missing"
 * branch and the {@code testUnavailable…} cases stop self-skipping.
 *
 * Extra arguments are forwarded to PHPUnit (e.g. {@code --filter Unavailable}).
 */
$projectRoot = dirname(__DIR__);
chdir($projectRoot);

$disabledFunctions = [
    'igbinary_serialize', 'igbinary_unserialize',
    'msgpack_pack', 'msgpack_unpack',
    'zstd_compress', 'zstd_uncompress',
    'fastlz_compress', 'fastlz_decompress',
];

$command = [
    \PHP_BINARY,
    '-d', 'disable_functions='.implode(',', $disabledFunctions),
    $projectRoot.'/vendor/bin/phpunit',
    '--configuration='.$projectRoot.'/config/phpunit.xml',
];

$command = array_merge($command, array_slice($argv, 1));

$process = proc_open($command, [
    0 => \STDIN,
    1 => \STDOUT,
    2 => \STDERR,
], $pipes, $projectRoot, null, ['bypass_shell' => true]);

if (!is_resource($process)) {
    fwrite(\STDERR, "Failed to start PHPUnit.\n");
    exit(1);
}

$code = proc_close($process);

exit(0 === $code ? 0 : ($code > 0 ? $code : 1));
