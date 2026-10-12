<?php

/*
 * Fails (exit 1) when line coverage in a clover report is below the threshold.
 * Usage: php tests/coverage-gate.php [clover.xml] [minimum-percent]
 */

$clover = $argv[1] ?? __DIR__ . '/../report/clover.xml';
$minimum = (float) ($argv[2] ?? 100);

if (! is_file($clover)) {
    fwrite(STDERR, "Coverage report not found: {$clover}\n");
    exit(1);
}

$xml = simplexml_load_file($clover);
$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? $covered / $statements * 100 : 100.0;

$uncovered = [];
foreach ($xml->xpath('//file') as $file) {
    $missing = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missing[] = (int) $line['num'];
        }
    }
    if ($missing !== []) {
        $uncovered[(string) $file['name']] = $missing;
    }
}

printf("Line coverage: %.2f%% (%d/%d statements), minimum %.2f%%\n", $percent, $covered, $statements, $minimum);

if ($percent + 1e-9 < $minimum) {
    $root = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR;
    foreach ($uncovered as $name => $lines) {
        fwrite(STDERR, '  ' . str_replace($root, '', $name) . ': ' . implode(', ', $lines) . "\n");
    }
    fwrite(STDERR, "Coverage gate failed.\n");
    exit(1);
}

echo "Coverage gate passed.\n";
