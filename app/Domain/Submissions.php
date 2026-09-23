<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Support\PersianText;
use App\Support\Settings;

/**
 * Articles sent in by contributors, and what happens to them.
 *
 *   pending ──judge──▶ pending (with a verdict)
 *      │                  │
 *      │   owner or, if allowed, an approving judge
 *      ▼                  ▼
 *   needs_changes      approved ──▶ a normal article, published
 *   rejected           withdrawn (by the contributor)
 *
 * The judge — an LLM in the worker — reads every submission and leaves a
 * verdict, a score and notes for the owner. With contributions.judge_can_publish
 * on, a confident approval publishes without waiting. The judge can never
 * reject or return a submission on its own: a false "no" would silently
 * discourage a real person, so only the owner does that.
 *
 * A submission is checked exactly as an owner-written article is, with one
 * difference: a citation to a reference that does not exist is refused at
 * the door rather than left as a finding.
 */
final class Submissions
{
    public const OPEN = ['pending', 'needs_changes'];

    // ------------------------------------------------------- contributor side

    /**
     * @return array{ok:true, id:int}|array{ok:false, errors:list<string>}
     */
    public static function submit(array $contributor, ?int $id, array $meta, mixed $input): array
    {
        // Switching contributions off closes the door for people already
        // signed in, too.
        if (!Settings::bool('contributions.enabled', true)) {
            return ['ok' => false, 'errors' => ['فرستادن نوشته فعلاً بسته است.']];
        }

        $contributorId = (int) $contributor['id'];
        $existing = null;

        if ($id !== null) {
            $existing = self::findFor($contributorId, $id);
            if ($existing === null || !in_array($existing['status'], self::OPEN, true)) {
                return ['ok' => false, 'errors' => ['این نوشته دیگر قابل ویرایش نیست.']];
            }
        } else {
            $open = (int) Database::value(
                "SELECT COUNT(*) FROM submissions WHERE contributor_id = :c AND status IN ('pending','needs_changes')",
                ['c' => $contributorId], 0
            );
            if ($open >= Config::int('contributions.max_pending', 3)) {
                return ['ok' => false, 'errors' => [PersianText::toPersianDigits(sprintf(
                    'شما %d نوشته در انتظار بررسی دارید. پس از بررسی آن‌ها می‌توانید نوشته تازه بفرستید.', $open
                ))]];
            }
            $today = (int) Database::value(
                'SELECT COUNT(*) FROM submissions WHERE contributor_id = :c AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
                ['c' => $contributorId], 0
            );
            if ($today >= Config::int('contributions.max_per_day', 5)) {
                return ['ok' => false, 'errors' => ['امروز به سقف ارسال رسیده‌اید. لطفاً فردا دوباره تلاش کنید.']];
            }
        }

        $normalized = ArticleComposer::normalize($input, 'fa');
        $doc = $normalized['doc'];
        $errors = $normalized['errors'];

        [$row, $metaErrors] = self::meta($meta, $doc, $contributorId);
        $errors = [...$errors, ...$metaErrors];

        // Images must be the contributor's own uploads.
        $own = self::ownMedia($contributorId, ArticleComposer::mediaIds($doc));
        foreach (ArticleComposer::mediaIds($doc) as $mediaId) {
            if (!in_array($mediaId, $own, true)) {
                $errors[] = 'یکی از تصویرها متعلق به شما نیست؛ آن را دوباره بارگذاری کنید.';
                break;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        $findings = CitationValidator::validate(ArticleComposer::draft($doc), ArticleComposer::sources($doc))['findings'];
        $invented = array_filter($findings, static fn(array $f) => $f['severity'] === CitationValidator::SEVERITY_ERROR);
        if ($invented !== []) {
            return ['ok' => false, 'errors' => array_values(array_map(self::explain(...), $invented))];
        }

        $row += [
            'doc'           => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'findings'      => json_encode(array_values($findings), JSON_UNESCAPED_UNICODE),
            'status'        => 'pending',
            'judge_verdict' => null,
            'judge_score'   => null,
            'judge_notes'   => null,
            'judge_model'   => null,
            'judged_at'     => null,
        ];

        if ($existing === null) {
            $id = Database::insert('submissions', [...$row, 'contributor_id' => $contributorId]);
            $revision = 1;
        } else {
            $revision = (int) $existing['revision'] + 1;
            Database::update('submissions', [...$row, 'revision' => $revision], 'id = :id', ['id' => $id]);
        }

        self::queueJudge($id, $revision);

        return ['ok' => true, 'id' => $id];
    }

    public static function withdraw(int $contributorId, int $id): bool
    {
        return Database::run(
            "UPDATE submissions SET status = 'withdrawn' WHERE id = :id AND contributor_id = :c AND status IN ('pending','needs_changes')",
            ['id' => $id, 'c' => $contributorId]
        )->rowCount() === 1;
    }

    /** @return list<array> newest first */
    public static function forContributor(int $contributorId): array
    {
        return Database::all(
            'SELECT s.id, s.title_fa, s.status, s.reviewer_note, s.created_at, s.updated_at, s.article_id,
                    a.slug AS article_slug, f.path AS article_field_path, a.status AS article_status
             FROM submissions s
             LEFT JOIN articles a ON a.id = s.article_id
             LEFT JOIN fields f ON f.id = a.field_id
             WHERE s.contributor_id = :c
             ORDER BY s.id DESC LIMIT 100',
            ['c' => $contributorId]
        );
    }

    public static function findFor(int $contributorId, int $id): ?array
    {
        return Database::first('SELECT * FROM submissions WHERE id = :id AND contributor_id = :c', ['id' => $id, 'c' => $contributorId]);
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT s.*, c.display_name, c.approved_count, c.rejected_count, c.status AS contributor_status, c.created_at AS contributor_since
             FROM submissions s INNER JOIN contributors c ON c.id = s.contributor_id
             WHERE s.id = :id',
            ['id' => $id]
        );
    }

    // ------------------------------------------------------------ owner side

    /**
     * Turn a submission into an article.
     *
     * @return array{ok:true, article_id:int, published:bool}|array{ok:false, errors:list<string>}
     */
    public static function approve(int $id, bool $publish, ?int $adminId, string $note = '', string $decidedBy = 'owner'): array
    {
        $submission = self::find($id);
        if ($submission === null || !in_array($submission['status'], self::OPEN, true)) {
            return ['ok' => false, 'errors' => ['This submission is no longer open.']];
        }

        $doc = json_decode((string) $submission['doc'], true) ?: [];
        $result = ArticleAuthoring::save(null, [
            'field_id'              => $submission['field_id'],
            'kind'                  => $submission['kind'],
            'title'                 => $submission['title_fa'],
            'summary'               => (string) ($submission['summary_fa'] ?? ''),
            'hero_media_id'         => $submission['hero_media_id'],
            'author_contributor_id' => $submission['contributor_id'],
            'author_display'        => $submission['display_name'],
        ], $doc, $adminId);

        if (!$result['ok']) {
            return ['ok' => false, 'errors' => $result['errors']];
        }

        $articleId = (int) $result['article_id'];

        Database::update('submissions', [
            'status'        => 'approved',
            'article_id'    => $articleId,
            'decided_by'    => $decidedBy,
            'reviewer_note' => $note !== '' ? mb_substr($note, 0, 2000, 'UTF-8') : null,
            'reviewed_by'   => $adminId,
            'reviewed_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        Database::run('UPDATE contributors SET approved_count = approved_count + 1 WHERE id = :id', ['id' => $submission['contributor_id']]);

        if ($publish) {
            Publisher::publish($articleId, $adminId, $decidedBy === 'judge' ? 'contribution approved by the judge' : 'contribution approved');
            Publisher::relinkMentioning($articleId);
        }

        return ['ok' => true, 'article_id' => $articleId, 'published' => $publish];
    }

    /** Return to the contributor with a note, or reject outright. */
    public static function decide(int $id, string $status, ?int $adminId, string $note): bool
    {
        if (!in_array($status, ['needs_changes', 'rejected'], true)) {
            return false;
        }

        $changed = Database::run(
            "UPDATE submissions
             SET status = :status, reviewer_note = :note, reviewed_by = :admin, reviewed_at = NOW(), decided_by = 'owner'
             WHERE id = :id AND status IN ('pending','needs_changes')",
            ['status' => $status, 'note' => mb_substr(trim($note), 0, 2000, 'UTF-8') ?: null, 'admin' => $adminId, 'id' => $id]
        )->rowCount() === 1;

        if ($changed && $status === 'rejected') {
            Database::run(
                'UPDATE contributors c INNER JOIN submissions s ON s.contributor_id = c.id SET c.rejected_count = c.rejected_count + 1 WHERE s.id = :id',
                ['id' => $id]
            );
        }

        return $changed;
    }

    /** @return list<array> */
    public static function queue(string $status = 'pending', int $limit = 100): array
    {
        return Database::all(
            'SELECT s.id, s.title_fa, s.kind, s.status, s.judge_verdict, s.judge_score, s.created_at, s.revision,
                    s.decided_by, c.display_name, c.approved_count, f.title_fa AS field_title
             FROM submissions s
             INNER JOIN contributors c ON c.id = s.contributor_id
             INNER JOIN fields f ON f.id = s.field_id
             WHERE s.status = :status
             ORDER BY s.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . '
             LIMIT ' . max(1, min(500, $limit)),
            ['status' => $status]
        );
    }

    public static function pendingCount(): int
    {
        try {
            return (int) Database::value("SELECT COUNT(*) FROM submissions WHERE status = 'pending'", [], 0);
        } catch (\Throwable) {
            return 0;   // before migration 003
        }
    }

    // ----------------------------------------------------------------- judge

    /** What the worker's judge reads. The content is data, never instructions. */
    public static function judgePayload(array $submission): array
    {
        $doc = json_decode((string) $submission['doc'], true) ?: [];
        $field = FieldRepository::find((int) $submission['field_id']);

        $body = [];
        foreach ([['heading' => '', 'blocks' => $doc['intro'] ?? []], ...($doc['sections'] ?? [])] as $section) {
            if ($section['heading'] !== '') {
                $body[] = '## ' . $section['heading'];
            }
            foreach ($section['blocks'] ?? [] as $block) {
                $body[] = match ($block['type']) {
                    'image' => '[image: ' . $block['text'] . ']',
                    'subheading' => '### ' . $block['text'],
                    'tip', 'note', 'warning' => '(' . $block['type'] . ') ' . $block['text'],
                    default => $block['text'],
                };
            }
        }

        return [
            'submission_id' => (int) $submission['id'],
            'revision'      => (int) $submission['revision'],
            'kind'          => $submission['kind'],
            'field'         => $field['title_fa'] ?? '',
            'title'         => $submission['title_fa'],
            'summary'       => (string) ($submission['summary_fa'] ?? ''),
            'body'          => implode("\n\n", $body),
            'recipe'        => $doc['recipe'] ?? null,
            'references'    => array_map(static fn(array $r, int $i) => [
                'n' => $i + 1, 'url' => $r['url'], 'title' => $r['title'], 'quote' => $r['quote'],
            ], $doc['references'] ?? [], array_keys($doc['references'] ?? [])),
            'findings'      => json_decode((string) ($submission['findings'] ?? '[]'), true) ?: [],
        ];
    }

    /**
     * Record the judge's verdict and, when allowed and warranted, publish.
     *
     * @return array{stored:bool, published:bool, reason:string}
     */
    public static function recordJudgement(int $id, int $revision, array $result): array
    {
        $submission = self::find($id);
        if ($submission === null || $submission['status'] !== 'pending' || (int) $submission['revision'] !== $revision) {
            // Edited, withdrawn or decided since the judge started: its view
            // is out of date.
            return ['stored' => false, 'published' => false, 'reason' => 'stale'];
        }

        $verdict = in_array($result['verdict'] ?? null, ['approve', 'revise', 'reject'], true) ? $result['verdict'] : null;
        $score = isset($result['score']) && is_numeric($result['score']) ? max(0, min(100, (int) $result['score'])) : null;
        if ($verdict === null || $score === null) {
            return ['stored' => false, 'published' => false, 'reason' => 'malformed verdict'];
        }

        $notes = [
            'summary'         => mb_substr((string) ($result['summary'] ?? ''), 0, 2000, 'UTF-8'),
            'issues'          => array_slice(array_values(array_filter((array) ($result['issues'] ?? []), 'is_array')), 0, 30),
            'food_safety_ok'  => (bool) ($result['food_safety_ok'] ?? false),
        ];

        Database::update('submissions', [
            'judge_verdict' => $verdict,
            'judge_score'   => $score,
            'judge_notes'   => json_encode($notes, JSON_UNESCAPED_UNICODE),
            'judge_model'   => mb_substr((string) ($result['model'] ?? ''), 0, 80, 'UTF-8') ?: null,
            'judged_at'     => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        $reason = self::autoPublishBlocker($submission, $verdict, $score, $notes);
        if ($reason !== null) {
            return ['stored' => true, 'published' => false, 'reason' => $reason];
        }

        $approved = self::approve($id, true, null, '', 'judge');

        return ['stored' => true, 'published' => $approved['ok'], 'reason' => $approved['ok'] ? 'published' : implode('; ', $approved['errors'])];
    }

    /** Why a judged submission must wait for the owner, or null if it need not. */
    private static function autoPublishBlocker(array $submission, string $verdict, int $score, array $notes): ?string
    {
        if (!Settings::bool('contributions.judge_can_publish', true)) {
            return 'the judge may not publish (setting)';
        }
        if ($verdict !== 'approve') {
            return "verdict {$verdict}";
        }
        if ($score < Config::int('contributions.judge_min_score', 80)) {
            return "score {$score} below threshold";
        }
        if (!$notes['food_safety_ok']) {
            return 'food safety not confirmed';
        }
        if ($submission['contributor_status'] !== 'active') {
            return 'contributor suspended';
        }
        foreach ($notes['issues'] as $issue) {
            if (in_array($issue['severity'] ?? '', ['blocker', 'major'], true)) {
                return 'the judge listed a major issue';
            }
        }
        $doc = json_decode((string) $submission['doc'], true) ?: [];
        if (($doc['references'] ?? []) === []) {
            return 'no references';
        }

        return null;
    }

    private static function queueJudge(int $id, int $revision): void
    {
        $submission = self::find($id);
        if ($submission === null) {
            return;
        }

        try {
            JobQueue::enqueue('judge', self::judgePayload($submission), null, mb_substr((string) $submission['title_fa'], 0, 300, 'UTF-8'), 4);
        } catch (\Throwable $e) {
            // The owner can still review it by hand.
            error_log("Could not queue the judge for submission {$id}: " . $e->getMessage());
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private static function meta(array $meta, array $doc, int $contributorId): array
    {
        $errors = [];

        $title = PersianText::display(trim(is_scalar($meta['title'] ?? null) ? (string) $meta['title'] : ''));
        if ($title === '') {
            $errors[] = 'نوشته به عنوان نیاز دارد.';
        } elseif (mb_strlen($title, 'UTF-8') > ArticleComposer::LIMITS['title']) {
            $errors[] = 'عنوان طولانی‌تر از حد مجاز است.';
        }

        $summary = PersianText::display(trim(is_scalar($meta['summary'] ?? null) ? (string) $meta['summary'] : ''));
        if (mb_strlen($summary, 'UTF-8') > ArticleComposer::LIMITS['summary']) {
            $errors[] = 'خلاصه طولانی‌تر از حد مجاز است.';
        }

        $kind = in_array($meta['kind'] ?? null, ArticleAuthoring::KINDS, true) ? (string) $meta['kind'] : '';
        if ($kind === '') {
            $errors[] = 'نوع نوشته را انتخاب کنید.';
        }
        if ($kind === 'recipe' && ($doc['recipe']['ingredients'] ?? []) === []) {
            $errors[] = 'دستور پخت دست‌کم به یک ماده لازم نیاز دارد.';
        }

        $field = FieldRepository::find((int) ($meta['field_id'] ?? 0));
        if ($field === null || !$field['is_published']) {
            $errors[] = 'بخشی را که نوشته به آن تعلق دارد انتخاب کنید.';
        }

        $heroId = (int) ($meta['hero_media_id'] ?? 0);
        if ($heroId > 0 && self::ownMedia($contributorId, [$heroId]) === []) {
            $errors[] = 'تصویر اصلی متعلق به شما نیست؛ آن را دوباره بارگذاری کنید.';
        }

        return [[
            'field_id'      => (int) ($field['id'] ?? 0),
            'kind'          => $kind ?: 'guide',
            'title_fa'      => $title,
            'summary_fa'    => $summary !== '' ? $summary : null,
            'hero_media_id' => $heroId > 0 ? $heroId : null,
        ], $errors];
    }

    /** @return list<int> the ids among $ids that this contributor uploaded */
    private static function ownMedia(int $contributorId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Database::inClause($ids, 'm');

        return array_map('intval', Database::column(
            "SELECT id FROM media WHERE contributor_id = :c AND id IN ({$in})",
            [...$params, 'c' => $contributorId]
        ));
    }

    /** A citation finding in words a contributor understands. */
    public static function explain(array $finding): string
    {
        $where = self::where((string) ($finding['anchor'] ?? ''));
        $message = (string) ($finding['message'] ?? '');

        $text = match ($finding['code'] ?? '') {
            'unknown_source' => preg_match('/\[(\d+)\]/', $message, $m) === 1
                ? "{$where} به منبع [{$m[1]}] ارجاع داده که در فهرست منابع نیست."
                : "{$where} به منبعی ارجاع داده که در فهرست منابع نیست.",
            'uncited_claim'       => "{$where} عدد یا مقدار مشخصی دارد اما به هیچ منبعی ارجاع نداده است.",
            'unsupported_number'  => preg_match('/"([^"]+)"/', $message, $m) === 1
                ? "{$where}: مقدار «{$m[1]}» در نقل‌قول منابعی که ارجاع داده‌اید دیده نمی‌شود."
                : "{$where}: یکی از مقدارها در منابع ارجاع‌شده دیده نمی‌شود.",
            'unverifiable_number' => "{$where}: برای عددهای این بند، از منبع یک نقل‌قول بیاورید تا قابل بررسی باشد.",
            'unused_source'       => preg_match('/\[(\d+)\]/', $message, $m) === 1
                ? "منبع [{$m[1]}] در متن ارجاع داده نشده است."
                : 'یکی از منابع در متن ارجاع داده نشده است.',
            default => $message,
        };

        return PersianText::toPersianDigits($text);
    }

    /** "s1p2" → "مقدمه، بند ۲"; "s3p1" → "بخش ۲، بند ۱". */
    private static function where(string $anchor): string
    {
        if (preg_match('/^s(\d+)p(\d+)$/', $anchor, $m) !== 1) {
            return 'یک بند';
        }

        return ((int) $m[1] === 1 ? 'مقدمه' : 'بخش ' . ((int) $m[1] - 1)) . '، بند ' . $m[2];
    }
}
