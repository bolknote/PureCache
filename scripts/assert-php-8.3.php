<?php

declare(strict_types=1);

/**
 * Fail when the active CLI is not PHP 8.3.x (library targets ^8.3 only).
 */
$version = \PHP_VERSION;
if (!str_starts_with($version, '8.3.')) {
    fwrite(
        \STDERR,
        sprintf(
            "PureCache CI expects PHP 8.3.x; active CLI is %s. Use phpenv/asdf/docker to select 8.3.\n",
            $version,
        ),
    );
    exit(1);
}

fwrite(\STDOUT, "PHP {$version} OK\n");
