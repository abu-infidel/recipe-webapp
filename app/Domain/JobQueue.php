<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;

/**
 * The research pipeline's work queue.
 *
 * The site owns the queue; the worker on the VPS abroad pulls from it. That
 * direction matters: the Iranian host makes no outbound connection at all, so
 * it needs no access to the outside internet and the API key never lives on
 * shared hosting.
 *
 * Claiming uses a lease. A worker that crashes mid-job simply stops renewing,
 * and the job returns to the queue by itself rather than needing anyone to
 * notice and reset it.
 */
final class JobQueue
{
    public const STAGES = [
        'plan',        // topic -> outline + research questions
        'search',      // questions -> candidate URLs
        'fetch',       // URLs -> extracted source text
        'synthesize',  // sources -> structured draft with refs
        'validate',    // mechanical citation check
        'persian',     // render into Persian, preserving ref markers
        'image',       // hero image
        'link',        // related topics and aliases
        'push',        // land the draft
    ];

    public static function enqueue(
        string $type,
        array $payload = [],
        ?int $articleId = null,
        ?string $topic = null,
        int $priority = 5,
        ?int $parentJobId = null,
    ): int {
        if (!in_array($type, self::STAGES, true)) {
            throw new \InvalidArgumentException("Unknown job type: {$type}");
        }

        return Database::insert('jobs', [
            'type'          => $type,
            'article_id'    => $articleId,
            'parent_job_id' => $parentJobId,
            'topic'         => $topic !== null ? mb_substr($topic, 0, 300, 'UTF-8') : null,
            'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            'status'        => 'queued',
            'priority'      => max(1, min(9, $priority)),
            'max_attempts'  => Config::int('worker.max_attempts', 3),
        ]);
    }

    /**
     * Claim the next job.
     *
     * The UPDATE-then-SELECT shape is deliberate: it claims a row atomically
     * without needing SELECT ... FOR UPDATE, which behaves badly under the
     * connection limits of shared hosting.
     */
    public static function claim(string $workerId): ?array
    {
        return Database::transaction(static function () use ($workerId): ?array {
            self::releaseExpiredLeases();

            $lease = Config::int('worker.lease_seconds', 900);
            $token = bin2hex(random_bytes(8));

            $claimed = Database::run(
                "UPDATE jobs
                 SET status = 'leased',
                     claimed_by = :worker,
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL :lease SECOND),
                     attempts = attempts + 1,
                     error = NULL
                 WHERE status = 'queued' AND attempts < max_attempts
                 ORDER BY priority ASC, id ASC
                 LIMIT 1",
                ['worker' => $workerId . ':' . $token, 'lease' => $lease]
            )->rowCount();

            if ($claimed === 0) {
                return null;
            }

            $job = Database::first(
                'SELECT * FROM jobs WHERE claimed_by = :worker LIMIT 1',
                ['worker' => $workerId . ':' . $token]
            );

            if ($job !== null) {
                self::log((int) $job['id'], (string) $job['type'], 'info', "claimed by {$workerId}");
            }

            return $job;
        });
    }

    public static function complete(int $jobId, array $result, int $costMicros = 0): void
    {
        Database::update('jobs', [
            'status'      => 'done',
            'result'      => json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}',
            'cost_micros' => $costMicros,
            'lease_expires_at' => null,
        ], 'id = :id', ['id' => $jobId]);

        self::log($jobId, 'complete', 'info', 'completed');
    }

    /**
     * Record a failure. The job goes back to the queue unless it has used up
     * its attempts, in which case it stays failed for a person to look at.
     */
    public static function fail(int $jobId, string $error): void
    {
        $job = Database::first('SELECT attempts, max_attempts, type FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return;
        }

        $exhausted = (int) $job['attempts'] >= (int) $job['max_attempts'];

        Database::update('jobs', [
            'status' => $exhausted ? 'failed' : 'queued',
            'error'  => mb_substr($error, 0, 2000, 'UTF-8'),
            'claimed_by' => null,
            'lease_expires_at' => null,
        ], 'id = :id', ['id' => $jobId]);

        self::log($jobId, (string) $job['type'], 'error', $error);
    }

    /** Extend a lease for a stage that legitimately takes a long time. */
    public static function heartbeat(int $jobId, string $workerId): bool
    {
        return Database::run(
            "UPDATE jobs
             SET lease_expires_at = DATE_ADD(NOW(), INTERVAL :lease SECOND)
             WHERE id = :id AND status = 'leased' AND claimed_by LIKE :worker",
            [
                'id' => $jobId,
                'lease' => Config::int('worker.lease_seconds', 900),
                'worker' => $workerId . ':%',
            ]
        )->rowCount() > 0;
    }

    /**
     * Put expired leases back in the queue. A worker that died mid-job needs
     * no manual intervention.
     */
    public static function releaseExpiredLeases(): int
    {
        return Database::run(
            "UPDATE jobs
             SET status = 'queued', claimed_by = NULL, lease_expires_at = NULL,
                 error = CONCAT(COALESCE(error, ''), ' [lease expired]')
             WHERE status = 'leased' AND lease_expires_at < NOW()"
        )->rowCount();
    }

    public static function log(int $jobId, string $stage, string $level, string $message, array $meta = []): void
    {
        try {
            Database::insert('job_events', [
                'job_id'  => $jobId,
                'stage'   => mb_substr($stage, 0, 40, 'UTF-8'),
                'level'   => in_array($level, ['debug', 'info', 'warn', 'error'], true) ? $level : 'info',
                'message' => mb_substr($message, 0, 1000, 'UTF-8'),
                'meta'    => $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null),
            ]);
        } catch (\Throwable $e) {
            error_log('Could not write job event: ' . $e->getMessage());
        }
    }

    /** @return array<string,int> */
    public static function stats(): array
    {
        $counts = Database::pairs('SELECT status, COUNT(*) FROM jobs GROUP BY status');

        return [
            'queued'    => (int) ($counts['queued'] ?? 0),
            'leased'    => (int) ($counts['leased'] ?? 0),
            'done'      => (int) ($counts['done'] ?? 0),
            'failed'    => (int) ($counts['failed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }

    /**
     * Queue the whole pipeline for a topic. Each stage is a separate job so a
     * failure retries only its own step rather than redoing the research.
     */
    public static function commission(string $topic, int $fieldId, string $kind = 'guide', int $priority = 5): int
    {
        return self::enqueue('plan', [
            'topic'    => $topic,
            'field_id' => $fieldId,
            'kind'     => $kind,
        ], null, $topic, $priority);
    }
}
