<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Ads;

/**
 * Anonymous counters.
 *
 * Pages are served from a static cache, so anything counted while a page
 * renders only counts cache misses — the numbers were meaningless, and each
 * one was a database write on a GET. Counting now happens here, from a beacon
 * the browser sends after the page is shown.
 *
 * A beacon carries a counter name and an id, nothing else. No cookie, no
 * identifier, no per-visitor row: each one adds one to an aggregate.
 */
final class BeaconController
{
    public static function record(Request $request): Response
    {
        $type = (string) ($request->input('t') ?? '');
        $id = (int) ($request->input('id') ?? 0);

        if ($id > 0) {
            match ($type) {
                // updated_at is set to itself on purpose. The column updates
                // automatically on any change to the row, so without this a
                // page view would move the article's "last updated" date —
                // shown to readers, sent as dateModified, and written into the
                // sitemap, where constant churn reads as low-quality content.
                'view' => Database::run(
                    "UPDATE articles SET view_count = view_count + 1, updated_at = updated_at
                     WHERE id = :id AND status = 'published'",
                    ['id' => $id]
                ),
                'ad' => Ads::recordImpression($id),
                default => null,
            };
        }

        // 204 whatever happened: a beacon has nobody waiting for the answer,
        // and a detailed response would only help someone probing ids.
        return Response::noContent()->noCache();
    }

    /**
     * Count a click on a house ad, then send the reader on. The destination is
     * re-read from the database and re-validated, so this cannot be turned
     * into an open redirect by editing the URL.
     */
    public static function adClick(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $target = $id > 0 ? Ads::clickTarget($id) : null;

        if ($target === null) {
            return Response::redirect('/', 302)->noCache();
        }

        Ads::recordClick($id);

        return Response::redirect($target, 302)
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->noCache();
    }
}
