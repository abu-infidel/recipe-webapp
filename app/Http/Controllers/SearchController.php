<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Http\Page;
use App\Http\ViewModels;
use App\Domain\SearchIndex;

final class SearchController
{
    private const MAX_QUERY_LENGTH = 120;

    public static function index(Request $request): Response
    {
        $query = self::query($request);
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = Config::int('search.results_per_page', 20);

        $results = $query === ''
            ? []
            : SearchIndex::search($query, $perPage + 1, ($page - 1) * $perPage);

        // Fetch one extra row to know whether a next page exists without
        // paying for a second COUNT query.
        $hasMore = count($results) > $perPage;
        $results = array_slice($results, 0, $perPage);

        return Page::render(
            'search',
            static fn(Theme $t) => ViewModels::search($t, $query, $results, $page, $hasMore)
        )->noCache();
    }

    /** Instant-search suggestions for the header box. */
    public static function json(Request $request): Response
    {
        $query = self::query($request);

        if (mb_strlen($query, 'UTF-8') < 2) {
            return Response::json(['suggestions' => []]);
        }

        return Response::json([
            'query'       => $query,
            'suggestions' => SearchIndex::suggest($query, 8),
        ])->cacheFor(60);
    }

    private static function query(Request $request): string
    {
        $query = trim((string) $request->query('q', ''));

        return mb_substr($query, 0, self::MAX_QUERY_LENGTH, 'UTF-8');
    }
}
