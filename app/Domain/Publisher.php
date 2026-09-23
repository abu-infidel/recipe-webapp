<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Core\PageCache;
use App\Core\Url;
use App\Support\AutoLinker;
use App\Support\HtmlSanitizer;
use App\Support\PersianText;
use App\Support\Slug;
use App\Support\Toc;

/**
 * Turns a draft into a published page.
 *
 * Everything expensive happens here, once, so that serving a page is a
 * handful of indexed reads: sanitising, heading ids, the contents tree,
 * internal links, the search index, the counters and the static cache.
 *
 * Publishing is transactional. If any step throws, the article stays exactly
 * as it was rather than going live half-processed.
 */
final class Publisher
{
    /**
     * Publish (or re-publish) an article.
     *
     * @return array{article_id:int, version:int, links:int, warnings:list<string>}
     */
    public static function publish(int $articleId, ?int $editorId = null, string $note = ''): array
    {
        $article = ArticleRepository::find($articleId);
        if ($article === null) {
            throw new \RuntimeException("Article {$articleId} does not exist.");
        }

        $field = FieldRepository::find((int) $article['field_id']);
        if ($field === null) {
            throw new \RuntimeException("Article {$articleId} has no field.");
        }

        $warnings = [];

        $result = Database::transaction(static function () use ($article, $field, $editorId, $note, &$warnings): array {
            $articleId = (int) $article['id'];

            // 1. Sanitise. The body came from the pipeline and may have been
            //    hand-edited since; it is never trusted.
            $html = HtmlSanitizer::clean((string) ($article['body_html'] ?? ''));

            if (trim(strip_tags($html)) === '') {
                $warnings[] = 'The article body is empty.';
            }

            // 2. Stable heading ids and the contents tree.
            $built = Toc::build($html);
            $html = $built['html'];

            if (count($built['toc']) < Config::int('content.toc_min_headings', 3)) {
                $warnings[] = 'Fewer headings than the contents sidebar needs; it will be hidden.';
            }

            // 3. Internal links to articles we already have.
            $linker = AutoLinker::fromRows(
                self::aliasRows($articleId),
                Config::int('content.autolink_per_target', 1),
                $articleId
            );
            $before = substr_count($html, 'data-internal');
            $html = $linker->apply($html);
            $linkCount = substr_count($html, 'data-internal') - $before;

            // 4. Snapshot the previous state before overwriting it, so every
            //    publish is reversible.
            $version = (int) $article['version'] + 1;
            Database::insert('article_versions', [
                'article_id' => $articleId,
                'version'    => (int) $article['version'],
                'snapshot'   => json_encode($article, JSON_UNESCAPED_UNICODE) ?: '{}',
                'editor_id'  => $editorId,
                'note'       => $note !== '' ? mb_substr($note, 0, 400, 'UTF-8') : null,
            ]);

            Database::update('articles', [
                'body_html'       => $html,
                'toc_json'        => json_encode($built['toc'], JSON_UNESCAPED_UNICODE),
                'reading_minutes' => PersianText::readingMinutes($html),
                'status'          => 'published',
                'version'         => $version,
                'published_at'    => $article['published_at'] ?: date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $articleId]);

            // 5. The title is always an alias of itself, so other articles
            //    mentioning it pick up a link.
            self::ensureTitleAlias($articleId, (string) $article['title_fa']);

            // 6. Search index.
            SearchIndex::index($articleId, [
                SearchIndex::ZONE_TITLE   => (string) $article['title_fa'],
                SearchIndex::ZONE_SUMMARY => (string) ($article['summary_fa'] ?? ''),
                SearchIndex::ZONE_HEADING => implode(' ', array_column(self::flattenToc($built['toc']), 'text')),
                SearchIndex::ZONE_BODY    => $html,
                SearchIndex::ZONE_ALIAS   => implode(' ', self::aliasesOf($articleId)),
            ]);

            return ['article_id' => $articleId, 'version' => $version, 'links' => max(0, $linkCount)];
        });

        // A republished article must not keep answering 410 Gone.
        self::clearGone((string) $field['path'] . '/' . $article['slug']);

        // Outside the transaction: these touch the filesystem, which cannot
        // be rolled back, so they run only once the database has committed.
        FieldRepository::recountAll();
        self::invalidate($field, $article);
        self::regenerateTree();

        return [...$result, 'warnings' => $warnings];
    }

    /** Take an article off the public site without deleting it. */
    public static function unpublish(int $articleId): void
    {
        $article = ArticleRepository::find($articleId);
        if ($article === null) {
            return;
        }

        $field = FieldRepository::find((int) $article['field_id']);

        Database::update('articles', ['status' => 'draft'], 'id = :id', ['id' => $articleId]);
        SearchIndex::remove($articleId);

        if ($field !== null) {
            self::markGone((string) $field['path'] . '/' . $article['slug']);
        }

        FieldRepository::recountAll();
        if ($field !== null) {
            self::invalidate($field, $article);
        }
        self::regenerateTree();
    }

    /**
     * Re-link older articles so they pick up a newly published topic.
     *
     * This is what makes the wiki grow denser on its own. Bounded per run,
     * because on shared hosting an unbounded pass would time out.
     */
    public static function relinkMentioning(int $newArticleId, int $limit = 25): int
    {
        $aliases = self::aliasesOf($newArticleId);
        if ($aliases === []) {
            return 0;
        }

        $conditions = [];
        $params = ['self' => $newArticleId, 'published' => 'published'];
        foreach ($aliases as $i => $alias) {
            $conditions[] = "a.body_html LIKE :alias{$i}";
            $params["alias{$i}"] = '%' . ArticleRepository::escapeLike($alias) . '%';
        }

        $candidates = Database::all(
            'SELECT a.id FROM articles a
             WHERE a.status = :published AND a.id != :self AND (' . implode(' OR ', $conditions) . ')
             ORDER BY a.published_at DESC
             LIMIT ' . max(1, min(100, $limit)),
            $params
        );

        $updated = 0;
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $row = ArticleRepository::find($id);
            if ($row === null) {
                continue;
            }

            $linker = AutoLinker::fromRows(
                self::aliasRows($id),
                Config::int('content.autolink_per_target', 1),
                $id
            );

            $linked = $linker->apply((string) $row['body_html']);
            if ($linked === $row['body_html']) {
                continue;
            }

            Database::update('articles', ['body_html' => $linked], 'id = :id', ['id' => $id]);

            $field = FieldRepository::find((int) $row['field_id']);
            if ($field !== null) {
                self::invalidate($field, $row);
            }
            $updated++;
        }

        return $updated;
    }

    /**
     * Record that a URL was removed on purpose, so it answers 410 Gone.
     * Search engines drop a 410 from their index far faster than a 404, and
     * it tells a reader the page is not merely mistyped.
     */
    public static function markGone(string $path): void
    {
        $path = trim($path, '/');

        Database::run(
            'INSERT INTO redirects (from_path, from_hash, to_path, status)
             VALUES (:path, :hash, \'\', 410)
             ON DUPLICATE KEY UPDATE to_path = \'\', status = 410',
            ['path' => $path, 'hash' => sha1($path)]
        );
    }

    public static function clearGone(string $path): void
    {
        Database::delete('redirects', 'from_hash = :hash AND status = 410', ['hash' => sha1(trim($path, '/'))]);
    }

    /**
     * Write the tree to a static file so the homepage menu needs no database.
     */
    public static function regenerateTree(): bool
    {
        $file = \App\Core\Paths::cache() . '/tree.json';
        $payload = json_encode(
            ['generated' => gmdate('c'), 'fields' => FieldRepository::tree()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            return false;
        }

        $temporary = $file . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return false;
        }

        return @rename($temporary, $file);
    }

    /**
     * Drop the cached pages an article affects: the article itself, its
     * field, every ancestor field, and the homepage.
     */
    private static function invalidate(array $field, array $article): void
    {
        $host = Config::string('site.domain');

        PageCache::forget($host, '/');
        PageCache::forget($host, '/' . $field['path'] . '/' . $article['slug']);
        PageCache::forget($host, '/' . $field['path']);

        $segments = explode('/', (string) $field['path']);
        while (count($segments) > 1) {
            array_pop($segments);
            PageCache::forget($host, '/' . implode('/', $segments));
        }
    }

    private static function ensureTitleAlias(int $articleId, string $title): void
    {
        $normalised = PersianText::normalize($title);
        if ($normalised === '') {
            return;
        }

        // alias_norm is unique across the site: one article owns a phrase.
        // An existing claim by another article is left alone rather than
        // stolen, so links do not silently change target on republish.
        Database::run(
            'INSERT INTO topic_aliases (article_id, alias, alias_norm, priority, is_auto)
             VALUES (:article, :alias, :norm, 10, 1)
             ON DUPLICATE KEY UPDATE alias = IF(article_id = VALUES(article_id), VALUES(alias), alias)',
            ['article' => $articleId, 'alias' => $title, 'norm' => $normalised]
        );
    }

    /** @return list<string> */
    private static function aliasesOf(int $articleId): array
    {
        return array_map(
            static fn($v) => (string) $v,
            Database::column('SELECT alias FROM topic_aliases WHERE article_id = :id', ['id' => $articleId])
        );
    }

    /**
     * Every alias pointing at a published article, for the linker.
     * Excludes the article being published so it cannot link to itself.
     */
    private static function aliasRows(int $excludeArticleId): array
    {
        return Database::all(
            "SELECT t.alias, t.alias_norm, t.article_id, a.slug, a.title_fa, f.path AS field_path
             FROM topic_aliases t
             INNER JOIN articles a ON a.id = t.article_id
             INNER JOIN fields f ON f.id = a.field_id
             WHERE a.status = 'published' AND t.article_id != :exclude
             ORDER BY t.priority DESC",
            ['exclude' => $excludeArticleId]
        );
    }

    /** @return list<array{id:string,text:string}> */
    private static function flattenToc(array $toc): array
    {
        $flat = [];

        foreach ($toc as $item) {
            $flat[] = ['id' => $item['id'], 'text' => $item['text']];
            if (!empty($item['children'])) {
                $flat = [...$flat, ...self::flattenToc($item['children'])];
            }
        }

        return $flat;
    }
}
