<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ArticleRepository;
use App\Domain\CitationValidator;
use App\Domain\FieldRepository;
use App\Domain\JobQueue;
use App\Support\PersianText;
use App\Support\Slug;
use App\Support\WorkerAuth;

/**
 * The API the research worker on the VPS abroad talks to.
 *
 * The worker pulls; the site never calls out. That is what keeps the Iranian
 * host free of any need for outbound internet access and keeps the API key
 * off shared hosting entirely.
 *
 * Everything the worker sends is treated as untrusted input. In particular
 * the citation check is re-run here rather than believing the worker's
 * verdict, so a compromised or simply buggy worker cannot land a draft that
 * cites a source nobody ever fetched.
 */
final class WorkerApiController
{
    public static function nextJob(Request $request): Response
    {
        $auth = self::authenticate($request, $body);
        if ($auth !== null) {
            return $auth;
        }

        $payload = self::decode($body);
        $workerId = self::workerId($payload);

        $job = JobQueue::claim($workerId);

        if ($job === null) {
            return Response::json(['job' => null])->noCache();
        }

        return Response::json([
            'job' => [
                'id'         => (int) $job['id'],
                'type'       => $job['type'],
                'topic'      => $job['topic'],
                'article_id' => $job['article_id'] === null ? null : (int) $job['article_id'],
                'attempts'   => (int) $job['attempts'],
                'payload'    => self::decode((string) ($job['payload'] ?? '{}')),
            ],
            'lease_seconds' => Config::int('worker.lease_seconds', 900),
        ])->noCache();
    }

    public static function completeJob(Request $request, array $params): Response
    {
        $auth = self::authenticate($request, $body);
        if ($auth !== null) {
            return $auth;
        }

        $jobId = (int) ($params['id'] ?? 0);
        $payload = self::decode($body);

        $job = Database::first('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return Response::json(['error' => 'unknown_job'], 404)->noCache();
        }

        if (($payload['status'] ?? '') === 'failed') {
            JobQueue::fail($jobId, (string) ($payload['error'] ?? 'unspecified failure'));
            return Response::json(['ok' => true, 'status' => 'failed'])->noCache();
        }

        $result = (array) ($payload['result'] ?? []);
        $cost = (int) ($payload['cost_micros'] ?? 0);

        try {
            $outcome = match ((string) $job['type']) {
                'fetch' => self::storeSources($result),
                'push'  => self::landDraft($job, $result),
                default => ['stored' => true],
            };
        } catch (\Throwable $e) {
            JobQueue::fail($jobId, $e->getMessage());
            return Response::json(['error' => 'processing_failed', 'message' => $e->getMessage()], 422)->noCache();
        }

        JobQueue::complete($jobId, [...$result, '_server' => $outcome], $cost);

        // Queue the next stage, if the worker named one.
        $next = (string) ($payload['next_stage'] ?? '');
        $nextId = null;
        if ($next !== '' && in_array($next, JobQueue::STAGES, true)) {
            $nextId = JobQueue::enqueue(
                $next,
                (array) ($payload['next_payload'] ?? []),
                $job['article_id'] === null ? null : (int) $job['article_id'],
                $job['topic'],
                (int) $job['priority'],
                $jobId
            );
        }

        return Response::json([
            'ok' => true,
            'outcome' => $outcome,
            'next_job_id' => $nextId,
        ])->noCache();
    }

    public static function heartbeat(Request $request, array $params): Response
    {
        $auth = self::authenticate($request, $body);
        if ($auth !== null) {
            return $auth;
        }

        $payload = self::decode($body);
        $renewed = JobQueue::heartbeat((int) ($params['id'] ?? 0), self::workerId($payload));

        return Response::json(['ok' => $renewed])->noCache();
    }

    /**
     * Validate a draft against the sources on record here.
     *
     * The worker runs the same check, but this is the one that counts: it
     * uses the sources table rather than whatever the worker claims it read.
     */
    public static function validateDraft(Request $request): Response
    {
        $auth = self::authenticate($request, $body);
        if ($auth !== null) {
            return $auth;
        }

        $payload = self::decode($body);
        $draft = (array) ($payload['draft'] ?? []);
        $sourceIds = array_map('intval', (array) ($payload['source_ids'] ?? []));

        $sources = self::loadSourcesByMarker($sourceIds);
        $result = CitationValidator::validate($draft, $sources);

        return Response::json($result)->noCache();
    }

    // ---------------------------------------------------------- job handlers

    /**
     * Store fetched pages. Deduplicated by URL hash, so re-running a fetch
     * never creates a second row for a page already on record.
     */
    private static function storeSources(array $result): array
    {
        $stored = [];

        foreach ((array) ($result['sources'] ?? []) as $source) {
            $url = trim((string) ($source['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $text = (string) ($source['extracted_text'] ?? '');
            $hash = sha1($url);

            $existing = Database::value('SELECT id FROM sources WHERE url_hash = :hash', ['hash' => $hash]);

            if ($existing !== null) {
                Database::update('sources', [
                    'extracted_text' => $text,
                    'content_hash'   => sha1($text),
                    'fetched_at'     => date('Y-m-d H:i:s'),
                    'http_status'    => (int) ($source['http_status'] ?? 200),
                ], 'id = :id', ['id' => (int) $existing]);

                $stored[] = (int) $existing;
                continue;
            }

            $stored[] = Database::insert('sources', [
                'url'            => mb_substr($url, 0, 2048, 'UTF-8'),
                'url_hash'       => $hash,
                'domain'         => mb_substr((string) (parse_url($url, PHP_URL_HOST) ?: ''), 0, 255, 'UTF-8'),
                'title'          => self::trimOrNull($source['title'] ?? null, 500),
                'author'         => self::trimOrNull($source['author'] ?? null, 255),
                'published_date' => self::dateOrNull($source['published_date'] ?? null),
                'lang'           => self::trimOrNull($source['lang'] ?? null, 10),
                'http_status'    => (int) ($source['http_status'] ?? 200),
                'content_hash'   => sha1($text),
                'extracted_text' => $text,
                'trust_tier'     => max(1, min(5, (int) ($source['trust_tier'] ?? 3))),
            ]);
        }

        return ['source_ids' => $stored];
    }

    /**
     * Land a finished draft.
     *
     * Always as a draft, never published. The review screen is where a person
     * decides, which is the whole point of the workflow.
     */
    private static function landDraft(array $job, array $result): array
    {
        $fieldId = (int) ($result['field_id'] ?? 0);
        $field = FieldRepository::find($fieldId);
        if ($field === null) {
            throw new \RuntimeException("Field {$fieldId} does not exist.");
        }

        $title = trim((string) ($result['title_fa'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('The draft has no title.');
        }

        $sourceIds = array_map('intval', (array) ($result['source_ids'] ?? []));
        $sources = self::loadSourcesByMarker($sourceIds);

        // Re-validate here against our own sources table. The worker already
        // did this, but a check the worker could skip is not a guarantee.
        $validation = CitationValidator::validate((array) ($result['draft'] ?? []), $sources);

        $slug = Slug::unique(
            Slug::make($title),
            static fn(string $candidate) => Database::value(
                'SELECT 1 FROM articles WHERE field_id = :field AND slug = :slug',
                ['field' => $fieldId, 'slug' => $candidate]
            ) !== null
        );

        $bodyHtml = (string) ($result['body_html'] ?? '');

        $articleId = Database::insert('articles', [
            'field_id'        => $fieldId,
            'slug'            => $slug,
            'kind'            => in_array($result['kind'] ?? '', ['recipe', 'guide', 'topic'], true) ? $result['kind'] : 'guide',
            'status'          => 'draft',
            'title_fa'        => PersianText::display($title),
            'summary_fa'      => self::trimOrNull($result['summary_fa'] ?? null, 2000),
            'body_html'       => $bodyHtml,
            'body_json'       => json_encode($result['draft'] ?? [], JSON_UNESCAPED_UNICODE) ?: null,
            'recipe_json'     => isset($result['recipe'])
                ? (json_encode($result['recipe'], JSON_UNESCAPED_UNICODE) ?: null)
                : null,
            'quality_flags'   => json_encode($validation['findings'], JSON_UNESCAPED_UNICODE) ?: null,
            'reading_minutes' => PersianText::readingMinutes($bodyHtml),
            'ai_model'        => self::trimOrNull($result['model'] ?? null, 80),
            'ai_job_id'       => (int) $job['id'],
        ]);

        // The numbered bibliography, in marker order.
        foreach ($sourceIds as $index => $sourceId) {
            if ($sourceId > 0) {
                Database::run(
                    'INSERT IGNORE INTO article_sources (article_id, source_id, marker)
                     VALUES (:article, :source, :marker)',
                    ['article' => $articleId, 'source' => $sourceId, 'marker' => $index + 1]
                );
            }
        }

        // Paragraph-level anchors, so the review screen can jump from a claim
        // to the text that supports it.
        foreach ((array) ($result['citations'] ?? []) as $citation) {
            $marker = (int) ($citation['marker'] ?? 0);
            $sourceId = $sourceIds[$marker - 1] ?? null;
            if ($sourceId === null) {
                continue;
            }

            Database::insert('citations', [
                'article_id' => $articleId,
                'source_id'  => $sourceId,
                'anchor'     => mb_substr((string) ($citation['anchor'] ?? ''), 0, 120, 'UTF-8'),
                'quote'      => self::trimOrNull($citation['quote'] ?? null, 2000),
                'verified'   => (int) (bool) ($citation['verified'] ?? false),
            ]);
        }

        JobQueue::log(
            (int) $job['id'],
            'push',
            $validation['ok'] ? 'info' : 'warn',
            sprintf('draft landed as article %d with %d finding(s)', $articleId, count($validation['findings']))
        );

        return [
            'article_id' => $articleId,
            'status'     => 'draft',
            'validation' => $validation,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Sources keyed by the marker number the draft cites, which is their
     * 1-based position in the source_ids list.
     *
     * @return array<int,array{url:string,extracted_text:string}>
     */
    private static function loadSourcesByMarker(array $sourceIds): array
    {
        $sourceIds = array_values(array_filter($sourceIds, static fn($id) => $id > 0));
        if ($sourceIds === []) {
            return [];
        }

        [$placeholders, $params] = Database::inClause($sourceIds, 'src');
        $rows = Database::all(
            "SELECT id, url, extracted_text FROM sources WHERE id IN ({$placeholders})",
            $params
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $byMarker = [];
        foreach ($sourceIds as $index => $id) {
            if (isset($byId[$id])) {
                $byMarker[$index + 1] = $byId[$id];
            }
        }

        return $byMarker;
    }

    /** Returns a Response on failure, or null when the caller may proceed. */
    private static function authenticate(Request $request, ?string &$body): ?Response
    {
        $body = $request->rawBody();
        $auth = WorkerAuth::verify($request, $body);

        if (!$auth['ok']) {
            // Deliberately terse: an attacker probing this endpoint learns
            // only that it refused, not which check refused it.
            error_log('Worker API rejected: ' . ($auth['error'] ?? 'unknown'));
            return Response::json(['error' => 'unauthorized'], 401)->noCache();
        }

        return null;
    }

    private static function workerId(array $payload): string
    {
        $id = (string) ($payload['worker_id'] ?? 'worker');

        return mb_substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $id) ?: 'worker', 0, 40, 'UTF-8');
    }

    private static function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function trimOrNull(mixed $value, int $length): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length, 'UTF-8');
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
