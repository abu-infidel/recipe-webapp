<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use App\Support\PersianText;
use App\Support\Slug;

/**
 * The content tree.
 *
 * Fields are stored as an adjacency list (parent_id) plus a materialised
 * `path`, so a lookup by URL is one indexed query and a whole subtree is one
 * prefix scan — no recursive CTE, which MariaDB 10.3 supports but executes
 * slowly on a shared host.
 */
final class FieldRepository
{
    public const MAX_DEPTH = 8;

    public static function findByPath(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '' || Slug::splitPath($path, self::MAX_DEPTH) === null) {
            return null;
        }

        return Database::first(
            'SELECT * FROM fields WHERE path_hash = :hash LIMIT 1',
            ['hash' => self::hashPath($path)]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM fields WHERE id = :id', ['id' => $id]);
    }

    /** Direct children, ordered for display. */
    public static function children(?int $parentId, bool $publishedOnly = true): array
    {
        $where = $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent';
        $params = $parentId === null ? [] : ['parent' => $parentId];

        if ($publishedOnly) {
            $where .= ' AND is_published = 1';
        }

        return Database::all(
            "SELECT * FROM fields WHERE {$where} ORDER BY sort_order ASC, title_fa ASC",
            $params
        );
    }

    /**
     * Ancestors of a field, root first, for the breadcrumb rail.
     * Derived from the path string, so it costs one query regardless of depth.
     */
    public static function ancestors(string $path): array
    {
        $segments = Slug::splitPath($path, self::MAX_DEPTH);
        if ($segments === null || count($segments) < 2) {
            return [];
        }

        array_pop($segments);   // exclude the field itself

        $hashes = [];
        $accumulated = '';
        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated . '/' . $segment;
            $hashes[] = self::hashPath($accumulated);
        }

        [$placeholders, $params] = Database::inClause($hashes, 'h');

        return Database::all(
            "SELECT * FROM fields WHERE path_hash IN ({$placeholders}) ORDER BY depth ASC",
            $params
        );
    }

    /**
     * The whole published tree as a nested structure.
     *
     * One query, assembled in PHP. This feeds /api/tree.json, which is written
     * to a static file on publish — the homepage menu then needs no database
     * at all and every drill-down is instant.
     */
    public static function tree(bool $publishedOnly = true): array
    {
        $where = $publishedOnly ? 'WHERE is_published = 1' : '';

        $rows = Database::all(
            "SELECT id, parent_id, slug, path, depth, title_fa, blurb_fa, icon,
                    accent_color, article_count, subtree_count
             FROM fields {$where}
             ORDER BY depth ASC, sort_order ASC, title_fa ASC"
        );

        $byId = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[(int) $row['id']] = $row;
        }

        $roots = [];
        foreach ($byId as $id => $row) {
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];

            if ($parentId !== null && isset($byId[$parentId])) {
                // Reference assembly: append to the parent by reference so the
                // nesting builds in a single pass.
                $byId[$parentId]['children'][] = &$byId[$id];
            } elseif ($parentId === null) {
                $roots[] = &$byId[$id];
            }
            // A child whose parent is unpublished is intentionally dropped:
            // an unpublished branch hides everything beneath it.
        }
        unset($row);

        return $roots;
    }

    /**
     * Create a field, deriving path/depth from the parent.
     *
     * @throws \InvalidArgumentException when the slug collides with a reserved
     *         top-level segment or the tree would grow too deep.
     */
    public static function create(array $data): int
    {
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $parent = $parentId !== null ? self::find($parentId) : null;

        if ($parentId !== null && $parent === null) {
            throw new \InvalidArgumentException('Parent field does not exist.');
        }

        $depth = $parent === null ? 0 : (int) $parent['depth'] + 1;
        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Maximum tree depth reached.');
        }

        $slug = Slug::make($data['slug'] ?? $data['title_fa']);

        if ($parent === null) {
            $reserved = (array) \App\Core\Config::get('routing.reserved_segments', []);
            if (in_array($slug, $reserved, true)) {
                throw new \InvalidArgumentException("\"{$slug}\" is reserved and cannot be a top-level field.");
            }
        }

        $prefix = $parent === null ? '' : $parent['path'] . '/';
        $slug = Slug::unique($slug, static fn(string $candidate) =>
            Database::value(
                'SELECT 1 FROM fields WHERE path_hash = :hash',
                ['hash' => self::hashPath($prefix . $candidate)]
            ) !== null);

        $path = $prefix . $slug;

        return Database::insert('fields', [
            'parent_id'    => $parentId,
            'slug'         => $slug,
            'path'         => $path,
            'path_hash'    => self::hashPath($path),
            'depth'        => $depth,
            'title_fa'     => PersianText::display($data['title_fa']),
            'blurb_fa'     => isset($data['blurb_fa']) ? PersianText::display($data['blurb_fa']) : null,
            'icon'         => $data['icon'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'is_published' => (int) ($data['is_published'] ?? 0),
        ]);
    }

    /**
     * Recalculate article_count and subtree_count for every field.
     *
     * Run after publishing or moving content. Cheap enough to do wholesale
     * (the tree is small) and far more reliable than incremental updates.
     */
    public static function recountAll(): void
    {
        Database::run(
            'UPDATE fields f SET article_count = (
                SELECT COUNT(*) FROM articles a
                WHERE a.field_id = f.id AND a.status = :published
             )',
            ['published' => 'published']
        );

        // Subtree counts roll up from the deepest level towards the root, so
        // each level can simply sum its already-correct children.
        $maxDepth = (int) Database::value('SELECT COALESCE(MAX(depth), 0) FROM fields', [], 0);

        for ($depth = $maxDepth; $depth >= 0; $depth--) {
            Database::run(
                'UPDATE fields f SET subtree_count = f.article_count + COALESCE((
                    SELECT SUM(c.subtree_count) FROM (SELECT * FROM fields) c
                    WHERE c.parent_id = f.id
                 ), 0)
                 WHERE f.depth = :depth',
                ['depth' => $depth]
            );
        }
    }

    public static function hashPath(string $path): string
    {
        return sha1(trim($path, '/'));
    }
}
