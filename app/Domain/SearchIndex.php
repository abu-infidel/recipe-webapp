<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Support\PersianText;

/**
 * A hand-built inverted index for Persian.
 *
 * InnoDB FULLTEXT is not usable here: its tokeniser enforces a three-character
 * minimum, ships an English stopword list, and knows nothing about ZWNJ or the
 * Arabic/Persian character variants that Persian keyboards produce
 * interchangeably. Those settings are server-wide and not changeable on shared
 * hosting, so the index lives in an ordinary table instead.
 *
 * Indexing happens at publish time. Querying is a single grouped scan of
 * search_tokens, which is covered by its primary key.
 */
final class SearchIndex
{
    public const ZONE_TITLE   = 1;
    public const ZONE_SUMMARY = 2;
    public const ZONE_HEADING = 3;
    public const ZONE_BODY    = 4;
    public const ZONE_ALIAS   = 5;

    private const ZONE_CONFIG_KEY = [
        self::ZONE_TITLE   => 'title',
        self::ZONE_SUMMARY => 'summary',
        self::ZONE_HEADING => 'heading',
        self::ZONE_BODY    => 'body',
        self::ZONE_ALIAS   => 'alias',
    ];

    /**
     * (Re)index one article. Replaces everything previously stored for it.
     *
     * @param array<int,string> $zones zone constant => raw text
     */
    public static function index(int $articleId, array $zones): void
    {
        Database::transaction(static function () use ($articleId, $zones): void {
            Database::delete('search_tokens', 'article_id = :id', ['id' => $articleId]);

            $rows = [];
            $total = 0;

            foreach ($zones as $zone => $text) {
                if (!is_string($text) || $text === '') {
                    continue;
                }

                $tokens = PersianText::tokenize(
                    strip_tags($text),
                    Config::int('search.min_token_length', 2)
                );

                // Term frequency within the zone.
                $frequencies = [];
                foreach ($tokens as $token) {
                    $token = mb_substr($token, 0, 64, 'UTF-8');
                    $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
                }

                foreach ($frequencies as $token => $tf) {
                    $rows[] = [$token, $zone, $articleId, min($tf, 65535)];
                    $total += $tf;
                }
            }

            // Batched multi-row insert: a 2,000-word article produces several
            // hundred rows and one statement per row would be painfully slow
            // on shared hosting.
            foreach (array_chunk($rows, 200) as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as $i => [$token, $zone, $id, $tf]) {
                    $placeholders[] = "(:t{$i}, :z{$i}, :a{$i}, :f{$i})";
                    $params["t{$i}"] = $token;
                    $params["z{$i}"] = $zone;
                    $params["a{$i}"] = $id;
                    $params["f{$i}"] = $tf;
                }

                Database::run(
                    'INSERT INTO search_tokens (token, zone, article_id, tf) VALUES '
                    . implode(', ', $placeholders)
                    . ' ON DUPLICATE KEY UPDATE tf = VALUES(tf)',
                    $params
                );
            }

            Database::run(
                'INSERT INTO search_docs (article_id, token_count, indexed_at)
                 VALUES (:id, :count, NOW())
                 ON DUPLICATE KEY UPDATE token_count = VALUES(token_count), indexed_at = NOW()',
                ['id' => $articleId, 'count' => $total]
            );
        });
    }

    public static function remove(int $articleId): void
    {
        Database::delete('search_tokens', 'article_id = :id', ['id' => $articleId]);
        Database::delete('search_docs', 'article_id = :id', ['id' => $articleId]);
    }

    /**
     * Search published articles.
     *
     * Scoring is tf * idf weighted by which zone the term appeared in, then
     * multiplied by how many of the query's distinct terms an article matched
     * — so an article containing every word beats one containing a single
     * word many times over.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $query, int $limit = 20, int $offset = 0): array
    {
        $tokens = PersianText::tokenize($query, Config::int('search.min_token_length', 2));
        $tokens = array_slice($tokens, 0, 12);   // bound the work a query can ask for

        if ($tokens === []) {
            return [];
        }

        $documentCount = (int) Database::value('SELECT COUNT(*) FROM search_docs', [], 0);
        if ($documentCount === 0) {
            return [];
        }

        // Document frequency per token, so rare words count for more.
        [$tokenPlaceholders, $tokenParams] = Database::inClause($tokens, 'tok');
        $frequencies = Database::pairs(
            "SELECT token, COUNT(DISTINCT article_id) FROM search_tokens
             WHERE token IN ({$tokenPlaceholders}) GROUP BY token",
            $tokenParams
        );

        if ($frequencies === []) {
            return [];
        }

        $idfCases = [];
        $idfParams = [];
        foreach ($tokens as $i => $token) {
            $df = (int) ($frequencies[$token] ?? 0);
            if ($df === 0) {
                continue;
            }
            $idfCases[] = "WHEN :idftok{$i} THEN :idfval{$i}";
            $idfParams["idftok{$i}"] = $token;
            $idfParams["idfval{$i}"] = log(1 + $documentCount / $df);
        }

        if ($idfCases === []) {
            return [];
        }

        $weights = (array) Config::get('search.weights', []);
        $zoneCases = [];
        foreach (self::ZONE_CONFIG_KEY as $zone => $key) {
            $zoneCases[] = sprintf('WHEN %d THEN %F', $zone, (float) ($weights[$key] ?? 1.0));
        }

        $sql = 'SELECT a.id, a.slug, a.title_fa, a.summary_fa, a.kind, a.reading_minutes,
                       a.published_at, f.path AS field_path, f.title_fa AS field_title,
                       SUM(
                           (CASE st.zone ' . implode(' ', $zoneCases) . ' ELSE 1 END)
                           * st.tf
                           * (CASE st.token ' . implode(' ', $idfCases) . ' ELSE 0 END)
                       ) * COUNT(DISTINCT st.token) AS score,
                       COUNT(DISTINCT st.token) AS matched_tokens
                FROM search_tokens st
                INNER JOIN articles a ON a.id = st.article_id
                INNER JOIN fields f ON f.id = a.field_id
                WHERE st.token IN (' . $tokenPlaceholders . ')
                  AND a.status = :published
                GROUP BY a.id, a.slug, a.title_fa, a.summary_fa, a.kind,
                         a.reading_minutes, a.published_at, f.path, f.title_fa
                HAVING score > 0
                ORDER BY score DESC, a.published_at DESC
                LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return Database::all($sql, [...$tokenParams, ...$idfParams, 'published' => 'published']);
    }

    /**
     * Prefix matches for the instant-search box.
     *
     * Only the title and alias zones are scanned: a body-wide prefix scan is
     * far too expensive to run on every keystroke on shared hosting.
     */
    public static function suggest(string $prefix, int $limit = 8): array
    {
        $normalized = PersianText::normalize($prefix);
        if (mb_strlen($normalized, 'UTF-8') < 2) {
            return [];
        }

        $last = (string) array_slice(explode(' ', $normalized), -1)[0];

        return Database::all(
            'SELECT DISTINCT a.id, a.slug, a.title_fa, a.kind, f.path AS field_path
             FROM search_tokens st
             INNER JOIN articles a ON a.id = st.article_id
             INNER JOIN fields f ON f.id = a.field_id
             WHERE st.token LIKE :prefix ESCAPE \'\\\\\'
               AND st.zone IN (:title, :alias)
               AND a.status = :published
             ORDER BY st.zone ASC, a.view_count DESC
             LIMIT ' . max(1, $limit),
            [
                'prefix'    => ArticleRepository::escapeLike($last) . '%',
                'title'     => self::ZONE_TITLE,
                'alias'     => self::ZONE_ALIAS,
                'published' => 'published',
            ]
        );
    }

    /**
     * A snippet of body text around the first match, with the matched terms
     * wrapped in <mark>. Everything is escaped before the marks go in, so a
     * body containing HTML cannot inject anything into the results page.
     */
    public static function snippet(string $body, string $query, ?int $radius = null): string
    {
        $radius ??= Config::int('search.snippet_radius', 90);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');

        if ($plain === '') {
            return '';
        }

        $tokens = PersianText::tokenize($query, 2);
        $position = false;

        foreach ($tokens as $token) {
            $position = mb_stripos($plain, $token, 0, 'UTF-8');
            if ($position !== false) {
                break;
            }
        }

        $start = $position === false ? 0 : max(0, $position - $radius);
        $snippet = mb_substr($plain, $start, $radius * 2, 'UTF-8');

        $escaped = htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach ($tokens as $token) {
            $quoted = preg_quote(htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');
            $escaped = preg_replace('/(' . $quoted . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
        }

        return ($start > 0 ? '…' : '') . $escaped . '…';
    }
}
