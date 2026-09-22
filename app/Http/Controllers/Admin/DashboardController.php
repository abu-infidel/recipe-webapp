<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\JobQueue;

final class DashboardController extends AdminController
{
    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $jobs = JobQueue::stats();

        return self::render($request, 'admin.dashboard', [
            'pageTitle' => 'Dashboard',
            'nav'       => 'dashboard',
            'jobs'      => $jobs,
            'counts'    => [
                'published' => (int) Database::value("SELECT COUNT(*) FROM articles WHERE status = 'published'", [], 0),
                'draft'     => (int) Database::value("SELECT COUNT(*) FROM articles WHERE status = 'draft'", [], 0),
                'review'    => (int) Database::value("SELECT COUNT(*) FROM articles WHERE status = 'in_review'", [], 0),
                'fields'    => (int) Database::value('SELECT COUNT(*) FROM fields', [], 0),
                'sources'   => (int) Database::value('SELECT COUNT(*) FROM sources', [], 0),
            ],
            'drafts' => Database::all(
                "SELECT a.id, a.title_fa, a.kind, a.created_at, a.quality_flags, f.title_fa AS field_title
                 FROM articles a INNER JOIN fields f ON f.id = a.field_id
                 WHERE a.status IN ('draft', 'in_review')
                 ORDER BY a.created_at DESC LIMIT 15"
            ),
            'worker' => self::workerStatus(),
            'spend'  => [
                'today' => (int) Database::value(
                    'SELECT COALESCE(SUM(cost_micros), 0) FROM jobs WHERE DATE(updated_at) = CURDATE()', [], 0
                ),
                'total' => (int) Database::value('SELECT COALESCE(SUM(cost_micros), 0) FROM jobs', [], 0),
            ],
            'recentEvents' => Database::all(
                'SELECT e.*, j.type AS job_type FROM job_events e
                 INNER JOIN jobs j ON j.id = e.job_id
                 ORDER BY e.id DESC LIMIT 20'
            ),
        ]);
    }

    /**
     * When the worker was last heard from.
     *
     * Derived from job activity rather than a separate ping, so it reflects
     * whether work is actually moving rather than whether a process is alive.
     */
    private static function workerStatus(): array
    {
        $lastSeen = Database::value(
            "SELECT MAX(updated_at) FROM jobs WHERE claimed_by IS NOT NULL OR status IN ('done','failed')"
        );

        $secondsAgo = $lastSeen === null ? null : max(0, time() - (int) strtotime((string) $lastSeen));

        return [
            'last_seen'   => $lastSeen,
            'seconds_ago' => $secondsAgo,
            'healthy'     => $secondsAgo !== null && $secondsAgo < 3600,
            'stuck'       => (int) Database::value(
                "SELECT COUNT(*) FROM jobs WHERE status = 'leased' AND lease_expires_at < NOW()", [], 0
            ),
        ];
    }
}
