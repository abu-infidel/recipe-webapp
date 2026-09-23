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

    /** Nobody reads page 5,000 of results, but a scraper asks for it. */
    private const MAX_PAGE = 50;

    public static function index(Request $request): Response
    {
        $query = self::query($request);
        $page = self::page($request->query('page'));
        [$results, $hasMore] = self::results($query, $page);

        return Page::render(
            'search',
            static fn(Theme $t) => ViewModels::search($t, $query, $results, $page, $hasMore)
        )->noCache();
    }

    /**
     * One page of results, and whether another page follows.
     *
     * @return array{0: list<array>, 1: bool}
     */
    public static function results(string $query, int $page): array
    {
        $perPage = Config::int('search.results_per_page', 20);

        $results = $query === ''
            ? []
            : SearchIndex::search($query, $perPage + 1, ($page - 1) * $perPage);

        // Fetch one extra row to know whether a next page exists without
        // paying for a second COUNT query.
        return [array_slice($results, 0, $perPage), count($results) > $perPage && $page < self::MAX_PAGE];
    }

    public static function page(mixed $value): int
    {
        return min(self::MAX_PAGE, max(1, is_scalar($value) ? (int) $value : 1));
    }

    public static function clean(string $query): string
    {
        return mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH, 'UTF-8');
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
        return self::clean((string) $request->query('q', ''));
    }
}
