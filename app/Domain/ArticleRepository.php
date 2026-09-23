<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;

/**
 * Articles: recipes, guides and topic pages.
 *
 * Reads here are deliberately narrow — the public site fetches only what a
 * page renders, because on a shared host the cost of dragging LONGTEXT body
 * columns through a listing query is the difference between a fast site and
 * a slow one.
 */
final class ArticleRepository
{
    /** Columns safe and cheap to select for any list view. */
    private const LIST_COLUMNS = 'id, field_id, slug, kind, status, title_fa, summary_fa,
                                  hero_media_id, reading_minutes, published_at, updated_at';

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM articles WHERE id = :id', ['id' => $id]);
    }

    public static function findInField(int $fieldId, string $slug, bool $publishedOnly = true): ?array
    {
        $sql = 'SELECT * FROM articles WHERE field_id = :field AND slug = :slug';
        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }

        return Database::first($sql . ' LIMIT 1', ['field' => $fieldId, 'slug' => $slug]);
    }

    /** Articles directly inside one field. */
    public static function inField(int $fieldId, int $limit = 50, int $offset = 0): array
    {
        return Database::all(
            'SELECT ' . self::LIST_COLUMNS . " FROM articles
             WHERE field_id = :field AND status = 'published'
             ORDER BY published_at DESC, id DESC
             LIMIT " . self::bound($limit) . ' OFFSET ' . max(0, min(100000, $offset)),
            ['field' => $fieldId]
        );
    }

    /**
     * Articles anywhere beneath a field, for a branch overview page.
     * Uses the materialised path prefix rather than walking the tree.
     */
    public static function inSubtree(string $fieldPath, int $limit = 50): array
    {
        $prefix = trim($fieldPath, '/');

        return Database::all(
            'SELECT a.' . str_replace(', ', ', a.', self::LIST_COLUMNS) . ', f.path AS field_path, f.title_fa AS field_title
             FROM articles a
             INNER JOIN fields f ON f.id = a.field_id
             WHERE a.status = :published
               AND (f.path = :exact OR f.path LIKE :prefix)
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT ' . self::bound($limit),
            ['published' => 'published', 'exact' => $prefix, 'prefix' => self::escapeLike($prefix) . '/%']
        );
    }

    public static function recent(int $limit = 12): array
    {
        return Database::all(
            'SELECT a.' . str_replace(', ', ', a.', self::LIST_COLUMNS) . ', f.path AS field_path
             FROM articles a
             INNER JOIN fields f ON f.id = a.field_id
             WHERE a.status = :published
             ORDER BY a.published_at DESC, a.id DESC
             LIMIT ' . self::bound($limit),
            ['published' => 'published']
        );
    }

    /**
     * The numbered bibliography for the References section.
     * Ordered by the marker shown in the body text.
     */
    public static function references(int $articleId): array
    {
        return Database::all(
            'SELECT s.id, s.url, s.domain, s.title, s.author, s.published_date,
                    s.trust_tier, asrc.marker, asrc.accessed_at
             FROM article_sources asrc
             INNER JOIN sources s ON s.id = asrc.source_id
             WHERE asrc.article_id = :article
             ORDER BY asrc.marker ASC',
            ['article' => $articleId]
        );
    }

    /**
     * Related topics, for the Wikipedia-style "see also" block.
     *
     * Explicit links first (the pipeline computed them), then a fallback to
     * siblings in the same field so a new article is never a dead end.
     */
    public static function related(int $articleId, int $fieldId, ?int $limit = null): array
    {
        $limit ??= Config::int('content.related_limit', 8);

        $linked = Database::all(
            'SELECT a.id, a.slug, a.title_fa, a.summary_fa, a.kind, f.path AS field_path, l.weight
             FROM article_links l
             INNER JOIN articles a ON a.id = l.to_article_id
             INNER JOIN fields f ON f.id = a.field_id
             WHERE l.from_article_id = :article AND a.status = :published
             ORDER BY l.weight DESC, a.title_fa ASC
             LIMIT ' . self::bound($limit, 50),
            ['article' => $articleId, 'published' => 'published']
        );

        if (count($linked) >= $limit) {
            return $linked;
        }

        $exclude = array_map(static fn($r) => (int) $r['id'], $linked);
        $exclude[] = $articleId;
        [$placeholders, $params] = Database::inClause($exclude, 'ex');

        $siblings = Database::all(
            'SELECT a.id, a.slug, a.title_fa, a.summary_fa, a.kind, f.path AS field_path, 0 AS weight
             FROM articles a
             INNER JOIN fields f ON f.id = a.field_id
             WHERE a.field_id = :field AND a.status = :published
               AND a.id NOT IN (' . $placeholders . ')
             ORDER BY a.published_at DESC
             LIMIT ' . self::bound($limit - count($linked), 50),
            ['field' => $fieldId, 'published' => 'published', ...$params]
        );

        return [...$linked, ...$siblings];
    }

    /** Neighbouring articles in the same field, for previous/next navigation. */
    public static function neighbours(int $articleId, int $fieldId): array
    {
        $rows = Database::all(
            "SELECT id, slug, title_fa FROM articles
             WHERE field_id = :field AND status = 'published'
             ORDER BY published_at ASC, id ASC",
            ['field' => $fieldId]
        );

        $index = null;
        foreach ($rows as $i => $row) {
            if ((int) $row['id'] === $articleId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $rows[$index - 1] ?? null,
            'next'     => $rows[$index + 1] ?? null,
        ];
    }

    /**
     * Bound a LIMIT. The parameters are int-typed under strict_types, so this
     * is not about injection — it stops any caller asking for a million rows.
     */
    private static function bound(int $value, int $max = 200): int
    {
        return max(1, min($max, $value));
    }

    /** Escape LIKE wildcards in user- or slug-derived input. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
