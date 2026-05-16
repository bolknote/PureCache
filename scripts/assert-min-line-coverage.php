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

if (!is_file($cloverPath)) {
    fwrite(\STDERR, "Clover report not found: {$cloverPath}\n");
    exit(1);
}

$xml = new SimpleXMLElement((string) file_get_contents($cloverPath));
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? 100.0 * $covered / $statements : 0.0;

fwrite(\STDOUT, sprintf("Line coverage: %.2f%% (%d/%d statements), minimum %.2f%%\n", $percent, $covered, $statements, $minimum));

if ($percent + 1e-9 < $minimum) {
    fwrite(\STDERR, sprintf("Line coverage %.2f%% is below the required %.2f%%\n", $percent, $minimum));
    exit(1);
}
