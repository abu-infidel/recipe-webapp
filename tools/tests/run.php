<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Test runner. Usage:  php tools/tests/run.php [FilterSubstring]
 */

require __DIR__ . '/../../app/autoload.php';
require __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? '';
$suites = [];

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
    $class = 'Tools\\Tests\\' . basename($file, '.php');
    if (class_exists($class) && ($filter === '' || str_contains($class, $filter))) {
        $suites[] = new $class();
    }
}

if ($suites === []) {
    fwrite(STDERR, "No test suites found" . ($filter !== '' ? " matching \"{$filter}\"" : '') . ".\n");
    exit(1);
}

$totalPassed = 0;
$allFailures = [];

foreach ($suites as $suite) {
    [$passed, $failures] = $suite->execute();
    $totalPassed += $passed;
    $allFailures = [...$allFailures, ...$failures];

    printf(
        "  %s %-34s %3d passed%s\n",
        $failures === [] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
        (new ReflectionClass($suite))->getShortName(),
        $passed,
        $failures === [] ? '' : sprintf(', %d failed', count($failures))
    );
}

echo "\n";

if ($allFailures !== []) {
    echo "\033[31mFailures:\033[0m\n\n";
    foreach ($allFailures as $i => $failure) {
        printf("  %d) %s\n\n", $i + 1, $failure);
    }
    printf("\033[31m%d assertion(s) failed\033[0m, %d passed.\n", count($allFailures), $totalPassed);
    exit(1);
}

printf("\033[32mAll %d assertions passed.\033[0m\n", $totalPassed);
exit(0);
