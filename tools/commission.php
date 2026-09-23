<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Queue a topic for the research pipeline.
 *
 *   php tools/commission.php "قیمه نثار قزوینی" ashpazi/khoresh recipe
 *
 * The same thing the admin panel's "commission a topic" button does.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Domain\FieldRepository;
use App\Domain\JobQueue;

$topic = $argv[1] ?? 'آش رشته';
$fieldPath = $argv[2] ?? 'ashpazi/soup';
$kind = $argv[3] ?? 'recipe';

$field = FieldRepository::findByPath($fieldPath);
if ($field === null) {
    fwrite(STDERR, "No field at path \"{$fieldPath}\".\n");
    exit(1);
}

$jobId = JobQueue::commission($topic, (int) $field['id'], $kind);

echo "Queued job {$jobId}: \"{$topic}\" -> {$fieldPath} ({$kind})\n";
