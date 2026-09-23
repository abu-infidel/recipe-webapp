<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Domain\AdminAuth;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;
use App\Domain\JobQueue;
use App\Domain\Publisher;
use App\Support\HtmlSanitizer;

/**
 * Article management, and the review screen the whole workflow exists for.
 */
final class ArticleController extends AdminController
{
    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $status = (string) ($request->query('status') ?? 'all');
        $where = in_array($status, ['draft', 'in_review', 'published', 'archived'], true)
            ? 'WHERE a.status = :status'
            : '';

        return self::render($request, 'admin.articles', [
            'pageTitle' => 'Articles',
            'nav'       => 'articles',
            'status'    => $status,
            'articles'  => Database::all(
                "SELECT a.id, a.title_fa, a.slug, a.kind, a.status, a.published_at, a.updated_at,
                        a.quality_flags, f.title_fa AS field_title, f.path AS field_path,
                        (SELECT COUNT(*) FROM article_sources s WHERE s.article_id = a.id) AS source_count
                 FROM articles a INNER JOIN fields f ON f.id = a.field_id
                 {$where}
                 ORDER BY a.updated_at DESC LIMIT 100",
                $where === '' ? [] : ['status' => $status]
            ),
        ]);
    }

    /**
     * The review screen.
     *
     * Persian draft on one side, the sources it was written from on the
     * other, every citation clickable, and the validator's findings inline.
     * This is where a person decides, which is the point of the workflow.
     */
    public static function review(Request $request, array $params): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $article = ArticleRepository::find((int) ($params['id'] ?? 0));
        if ($article === null) {
            return Response::text('Article not found', 404);
        }

        $field = FieldRepository::find((int) $article['field_id']);

        $sources = Database::all(
            'SELECT s.*, asrc.marker
             FROM article_sources asrc
             INNER JOIN sources s ON s.id = asrc.source_id
             WHERE asrc.article_id = :id
             ORDER BY asrc.marker',
            ['id' => (int) $article['id']]
        );

        // Paragraph-level links from a claim to the text supporting it.
        $citations = [];
        foreach (Database::all(
            'SELECT c.*, asrc.marker FROM citations c
             LEFT JOIN article_sources asrc ON asrc.article_id = c.article_id AND asrc.source_id = c.source_id
             WHERE c.article_id = :id',
            ['id' => (int) $article['id']]
        ) as $row) {
            $citations[(string) $row['anchor']][] = $row;
        }

        $flags = json_decode((string) ($article['quality_flags'] ?? '[]'), true);

        return self::render($request, 'admin.review', [
            'pageTitle'  => 'Review: ' . $article['title_fa'],
            'nav'        => 'articles',
            'article'    => $article,
            'field'      => $field,
            'sources'    => $sources,
            'citations'  => $citations,
            'flags'      => is_array($flags) ? $flags : [],
            'publicUrl'  => $field !== null
                ? Url::article((string) $field['path'], (string) $article['slug'])
                : null,
            'events'     => $article['ai_job_id'] === null ? [] : Database::all(
                'SELECT * FROM job_events WHERE job_id = :job ORDER BY id LIMIT 50',
                ['job' => (int) $article['ai_job_id']]
            ),
            'scripts'    => ['/assets/js/admin.js'],
        ]);
    }

    /** Save edits made on the review screen. */
    public static function save(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $article = ArticleRepository::find($id);
        if ($article === null) {
            return Response::text('Article not found', 404);
        }

        Database::update('articles', [
            'title_fa'   => trim((string) $request->input('title_fa', $article['title_fa'])),
            'summary_fa' => trim((string) $request->input('summary_fa', '')),
            // Stored sanitised, so every reader of this column can rely on it.
            'body_html'  => HtmlSanitizer::clean((string) $request->input('body_html', $article['body_html'])),
        ], 'id = :id', ['id' => $id]);

        AdminAuth::audit(
            AdminAuth::user($request)['id'] ?? null,
            'article_edit', 'article', $id, $request
        );

        return self::redirectWith("/admin/articles/{$id}", 'Saved.');
    }

    public static function publish(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $user = AdminAuth::user($request);

        try {
            $result = Publisher::publish($id, $user['id'] ?? null, (string) $request->input('note', ''));
            // A newly published topic makes older articles linkable to it.
            Publisher::relinkMentioning($id);
        } catch (\Throwable $e) {
            return self::redirectWith("/admin/articles/{$id}", 'Publish failed: ' . $e->getMessage(), 'error');
        }

        AdminAuth::audit($user['id'] ?? null, 'article_publish', 'article', $id, $request, $result);

        $message = "Published (version {$result['version']}, {$result['links']} internal link(s))";
        if ($result['warnings'] !== []) {
            $message .= '. ' . implode(' ', $result['warnings']);
        }

        return self::redirectWith("/admin/articles/{$id}", $message);
    }

    public static function unpublish(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        Publisher::unpublish($id);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'article_unpublish', 'article', $id, $request);

        return self::redirectWith("/admin/articles/{$id}", 'Taken off the public site.');
    }

    /** Queue a new topic for the research pipeline. */
    public static function commission(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $topic = trim((string) $request->input('topic', ''));
        $fieldId = (int) $request->input('field_id', 0);
        $kind = (string) $request->input('kind', 'guide');

        if ($topic === '' || FieldRepository::find($fieldId) === null) {
            return self::redirectWith('/admin/articles', 'Pick a field and enter a topic.', 'error');
        }

        $jobId = JobQueue::commission($topic, $fieldId, $kind);

        AdminAuth::audit(
            AdminAuth::user($request)['id'] ?? null,
            'commission', 'job', $jobId, $request, ['topic' => $topic]
        );

        return self::redirectWith('/admin/articles', "Queued as job {$jobId}. The worker will pick it up.");
    }
}
