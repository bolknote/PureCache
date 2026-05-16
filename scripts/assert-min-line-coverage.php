<?php

declare(strict_types=1);

/**
 * Fail when Clover line coverage is below the requested minimum (unit suite only).
 *
 * Usage: php scripts/assert-min-line-coverage.php <clover.xml> <min-percent>
 */
if ($argc < 3) {
    fwrite(\STDERR, "Usage: php scripts/assert-min-line-coverage.php <clover.xml> <min-percent>\n");
    exit(2);
}

$cloverPath = $argv[1];
$minimum = (float) $argv[2];
/** Extra covered statements above the percent floor so CI does not fail on a 0.01% flake. */
$statementBuffer = 4;

if (!is_file($cloverPath)) {
    fwrite(\STDERR, "Clover report not found: {$cloverPath}\n");
    exit(1);
}

$xml = new SimpleXMLElement((string) file_get_contents($cloverPath));
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? 100.0 * $covered / $statements : 0.0;

$requiredCovered = (int) ceil($statements * $minimum / 100) + $statementBuffer;

fwrite(\STDOUT, sprintf(
    "Line coverage: %.2f%% (%d/%d statements), minimum %.2f%% (>= %d covered, +%d buffer)\n",
    $percent,
    $covered,
    $statements,
    $minimum,
    $requiredCovered,
    $statementBuffer,
));

if ($percent + 1e-9 < $minimum || $covered < $requiredCovered) {
    fwrite(\STDERR, sprintf(
        "Line coverage %.2f%% (%d/%d) is below the required %.2f%% (need at least %d covered statements)\n",
        $percent,
        $covered,
        $statements,
        $minimum,
        $requiredCovered,
    ));
    exit(1);
}
