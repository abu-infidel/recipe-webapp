<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Regenerates the key reference in docs/UI-CONTRACT.md from UiContract.
 *
 *   php tools/ui-contract-doc.php
 *
 * Run it after changing the schema. UiContractTest fails while the document
 * is out of date, so the prose and the code cannot quietly disagree.
 */

require __DIR__ . '/../app/bootstrap.php';

$file = __DIR__ . '/../docs/UI-CONTRACT.md';
$begin = '<!-- BEGIN GENERATED REFERENCE: php tools/ui-contract-doc.php -->';
$end = '<!-- END GENERATED REFERENCE -->';

$doc = (string) file_get_contents($file);
$start = strpos($doc, $begin);
$stop = strpos($doc, $end);

if ($start === false || $stop === false || $stop < $start) {
    fwrite(STDERR, "docs/UI-CONTRACT.md is missing the generated-reference markers.\n");
    exit(1);
}

$updated = substr($doc, 0, $start + strlen($begin)) . "\n\n"
    . App\Http\UiContract::markdownReference() . "\n"
    . substr($doc, $stop);

if ($updated === $doc) {
    echo "docs/UI-CONTRACT.md is up to date.\n";
    exit(0);
}

file_put_contents($file, $updated);
echo "Updated docs/UI-CONTRACT.md.\n";
