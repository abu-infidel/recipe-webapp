<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AdminAuth;
use App\Domain\ArticleComposer;
use App\Domain\ContributorAuth;
use App\Domain\FieldRepository;
use App\Domain\MediaStore;
use App\Domain\Submissions;

/**
 * The moderation queue for contributed articles.
 *
 * Each submission shows the rendered article, the citation check, and the
 * judge's verdict and notes. The owner approves (publishing or keeping it as
 * a draft to polish in the composer), returns it with a note, or rejects it.
 */
final class SubmissionController extends AdminController
{
    private const STATUSES = ['pending' => 'Pending', 'needs_changes' => 'Returned', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $status = array_key_exists((string) $request->query('status'), self::STATUSES) ? (string) $request->query('status') : 'pending';

        return self::render($request, 'admin.submissions', [
            'pageTitle' => 'Submissions',
            'nav'       => 'submissions',
            'status'    => $status,
            'statuses'  => self::STATUSES,
            'items'     => Submissions::queue($status),
        ]);
    }

    public static function show(Request $request, array $params): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $submission = Submissions::find((int) ($params['id'] ?? 0));
        if ($submission === null) {
            return Response::text('Submission not found', 404);
        }

        $doc = json_decode((string) $submission['doc'], true) ?: [];
        $media = MediaStore::byIds([...ArticleComposer::mediaIds($doc), (int) $submission['hero_media_id']]);

        return self::render($request, 'admin.submission', [
            'pageTitle'  => 'Submission',
            'nav'        => 'submissions',
            'submission' => $submission,
            'doc'        => $doc,
            'field'      => FieldRepository::find((int) $submission['field_id']),
            'preview'    => ArticleComposer::render($doc, $media),
            'hero'       => $media[(int) $submission['hero_media_id']] ?? null,
            'findings'   => json_decode((string) ($submission['findings'] ?? '[]'), true) ?: [],
            'judge'      => json_decode((string) ($submission['judge_notes'] ?? 'null'), true),
            'recipe'     => $doc['recipe'] ?? null,
        ]);
    }

    public static function approve(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $publish = $request->input('publish') === '1';
        $user = AdminAuth::user($request);

        try {
            $result = Submissions::approve($id, $publish, $user['id'] ?? null, trim((string) $request->input('note', '')));
        } catch (\Throwable $e) {
            return self::redirectWith("/admin/submissions/{$id}", 'Approval failed: ' . $e->getMessage(), 'error');
        }

        if (!$result['ok']) {
            return self::redirectWith("/admin/submissions/{$id}", implode(' ', $result['errors']), 'error');
        }

        AdminAuth::audit($user['id'] ?? null, 'submission_approve', 'submission', $id, $request, ['article_id' => $result['article_id'], 'published' => $publish]);

        return $publish
            ? self::redirectWith('/admin/submissions', 'Approved and published.')
            : self::redirectWith('/admin/articles/' . $result['article_id'] . '/edit', 'Approved as a draft. Polish it here, then publish from the review screen.');
    }

    public static function decide(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $status = (string) $request->input('status', '');
        $note = trim((string) $request->input('note', ''));

        if ($status === 'needs_changes' && $note === '') {
            return self::redirectWith("/admin/submissions/{$id}", 'Say what should change — the contributor sees this note.', 'error');
        }

        $user = AdminAuth::user($request);
        if (!Submissions::decide($id, $status, $user['id'] ?? null, $note)) {
            return self::redirectWith("/admin/submissions/{$id}", 'This submission is no longer open.', 'error');
        }

        AdminAuth::audit($user['id'] ?? null, 'submission_' . $status, 'submission', $id, $request);

        return self::redirectWith('/admin/submissions', $status === 'rejected' ? 'Rejected.' : 'Returned to the contributor.');
    }

    /** Suspend or reinstate a contributor. Suspension signs them out everywhere. */
    public static function suspend(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $contributorId = (int) ($params['id'] ?? 0);
        $suspend = $request->input('suspend') === '1';

        Database::run('UPDATE contributors SET status = :s WHERE id = :id', ['s' => $suspend ? 'suspended' : 'active', 'id' => $contributorId]);
        if ($suspend) {
            ContributorAuth::endAllSessions($contributorId);
            Database::run(
                "UPDATE submissions SET status = 'rejected', reviewer_note = COALESCE(reviewer_note, 'حساب غیرفعال شد.'), decided_by = 'owner', reviewed_at = NOW()
                 WHERE contributor_id = :id AND status IN ('pending','needs_changes')",
                ['id' => $contributorId]
            );
        }

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, $suspend ? 'contributor_suspend' : 'contributor_reinstate', 'contributor', $contributorId, $request);

        $back = (int) $request->input('back', '0');

        return self::redirectWith($back > 0 ? "/admin/submissions/{$back}" : '/admin/submissions', $suspend ? 'Contributor suspended; their open submissions were closed.' : 'Contributor reinstated.');
    }
}
