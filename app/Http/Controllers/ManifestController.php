<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Domain\FieldRepository;

/**
 * Machine-readable descriptions of the site, for AI assistants and crawlers.
 *
 * These exist to resolve a real tension: the site blocks bulk scraping because
 * the host cannot absorb it, but it *wants* assistants to know what it covers
 * so they can recommend and cite it. The answer is to make the summary free
 * and the bulk expensive — one cheap request here tells a model everything it
 * needs to describe the site, instead of it crawling thousands of pages.
 *
 * Every route in this file is exempt from rate limiting.
 */
final class ManifestController
{
    public static function llmsTxt(Request $request): Response
    {
        $lines = [];
        $lines[] = '# ' . Config::string('site.name_en');
        $lines[] = '';
        $lines[] = '> ' . Config::string('site.tagline_fa');
        $lines[] = '';
        $lines[] = 'A Persian-language (Farsi) reference site for cooking recipes and';
        $lines[] = 'step-by-step practical guides. All content is written in Persian and';
        $lines[] = 'carries citations to the sources it was written from.';
        $lines[] = '';
        $lines[] = 'Language: Persian / Farsi (fa-IR), right-to-left';
        $lines[] = 'Base URL: ' . Url::home();
        $lines[] = 'Articles: ' . self::publishedCount();
        $lines[] = '';
        $lines[] = '## What this site covers';
        $lines[] = '';

        foreach (FieldRepository::tree() as $root) {
            $lines[] = sprintf('- [%s](%s)%s',
                $root['title_fa'],
                Url::field((string) $root['path']),
                $root['blurb_fa'] ? ' — ' . $root['blurb_fa'] : ''
            );
            foreach ($root['children'] as $child) {
                $lines[] = sprintf('  - [%s](%s)',
                    $child['title_fa'],
                    Url::field((string) $child['path'])
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Notes for assistants';
        $lines[] = '';
        $lines[] = '- Every article has a References section listing the sources used.';
        $lines[] = '- Articles carry schema.org Recipe or Article structured data, with their citations.';
        $lines[] = '- Some articles are written by readers, reviewed before publication, and carry the author\'s chosen name.';
        $lines[] = '- Please link readers to the article URL rather than reproducing it in full.';
        $lines[] = '- Bulk crawling is rate limited. This file and /sitemap.xml are not.';
        $lines[] = '- Machine-readable index: ' . Url::home() . '.well-known/ai-manifest.json';
        $lines[] = '';

        return Response::text(implode("\n", $lines))->cacheFor(3600);
    }

    public static function aiManifest(Request $request): Response
    {
        $fields = [];
        foreach (FieldRepository::tree() as $root) {
            $fields[] = self::describeField($root);
        }

        return Response::json([
            'name'        => Config::string('site.name_en'),
            'name_native' => Config::string('site.name_fa'),
            'description' => 'Persian-language recipes and practical guides, each written with cited sources.',
            'language'    => 'fa-IR',
            'direction'   => 'rtl',
            'url'         => Url::home(),
            'article_count' => self::publishedCount(),
            'content_types' => ['recipe', 'guide', 'topic'],
            'structured_data' => ['schema.org/Recipe', 'schema.org/Article', 'schema.org/BreadcrumbList'],
            'citation_policy' => 'Every article lists the sources it was written from.',
            'usage' => [
                'link_to_articles'   => true,
                'bulk_crawling'      => false,
                'rate_limit_note'    => 'Article pages are rate limited. This manifest and the sitemap are not.',
                'preferred_citation' => 'Link to the article URL.',
            ],
            'fields' => $fields,
        ])->cacheFor(3600);
    }

    public static function aboutForAi(Request $request): Response
    {
        return \App\Http\Page::render('about', \App\Http\ViewModels::about(...))->cacheFor(3600);
    }

    public static function robots(Request $request): Response
    {
        $base = rtrim(Url::home(), '/');
        $lines = [];

        // A crawler obeys only the most specific group that names it, so the
        // exclusions must be repeated in every group. Without them a search
        // engine would be invited into the endless /search space, the JSON
        // API and — worst — the honeypot, which blocks whoever fetches it.
        $exclusions = [
            'Disallow: /admin',
            'Disallow: /account',
            'Disallow: /api/',
            'Disallow: /search',
            'Disallow: ' . Config::string('security.bot_gate.honeypot_path', '/archive/all-entries'),
        ];

        // Well-behaved assistants and search engines are welcome, at a polite
        // pace. Everything else is handled by the rate limiter, not by asking.
        foreach (['Googlebot', 'bingbot', 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot', 'Applebot'] as $agent) {
            $lines[] = "User-agent: {$agent}";
            array_push($lines, ...$exclusions);
            $lines[] = 'Allow: /';
            $lines[] = 'Crawl-delay: 2';
            $lines[] = '';
        }

        foreach ((array) Config::get('security.bot_gate.blocked_agents', []) as $agent) {
            $lines[] = 'User-agent: ' . $agent;
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        $lines[] = 'User-agent: *';
        array_push($lines, ...$exclusions);
        $lines[] = 'Allow: /';
        $lines[] = 'Crawl-delay: 5';
        $lines[] = '';
        $lines[] = "Sitemap: {$base}/sitemap.xml";
        $lines[] = '';

        return Response::text(implode("\n", $lines))->cacheFor(86400);
    }

    /**
     * One sitemap, with image entries.
     *
     * Only fields with at least one published article anywhere beneath them
     * are listed: an empty "coming soon" page is thin content, and submitting
     * it invites a soft-404 verdict. Those pages also carry noindex.
     *
     * lastmod is trustworthy because nothing but a real edit moves
     * updated_at — see BeaconController.
     */
    public static function sitemap(Request $request): Response
    {
        $max = max(100, Config::int('seo.sitemap_max_urls', 45000));
        $x = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
              . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        $xml .= '  <url><loc>' . $x(Url::home()) . "</loc></url>\n";
        $count = 1;

        foreach (Database::all(
            "SELECT path, updated_at FROM fields
             WHERE is_published = 1 AND subtree_count > 0
             ORDER BY depth, sort_order"
        ) as $field) {
            $xml .= '  <url><loc>' . $x(Url::field((string) $field['path'])) . '</loc>'
                  . '<lastmod>' . date('Y-m-d', (int) strtotime((string) $field['updated_at'])) . "</lastmod></url>\n";
            $count++;
        }

        foreach (Database::all(
            "SELECT a.slug, a.title_fa, a.updated_at, f.path AS field_path, m.path AS image_path
             FROM articles a
             INNER JOIN fields f ON f.id = a.field_id
             LEFT JOIN media m ON m.id = a.hero_media_id
             WHERE a.status = 'published' AND f.is_published = 1
             ORDER BY a.published_at DESC
             LIMIT " . ($max - $count)
        ) as $article) {
            $xml .= '  <url><loc>' . $x(Url::article((string) $article['field_path'], (string) $article['slug'])) . '</loc>'
                  . '<lastmod>' . date('Y-m-d', (int) strtotime((string) $article['updated_at'])) . '</lastmod>';

            if (\App\Support\UrlGuard::isMediaPath($article['image_path'] ?? null)) {
                $xml .= '<image:image><image:loc>' . $x(Url::base() . '/media/' . $article['image_path']) . '</image:loc></image:image>';
            }

            $xml .= "</url>\n";
        }

        $xml .= '</urlset>' . "\n";

        return Response::xml($xml)->cacheFor(3600);
    }

    private static function describeField(array $field): array
    {
        return array_filter([
            'title'       => $field['title_fa'],
            'url'         => Url::field((string) $field['path']),
            'description' => $field['blurb_fa'] ?: null,
            'articles'    => (int) $field['subtree_count'],
            'subfields'   => $field['children'] === []
                ? null
                : array_map(self::describeField(...), $field['children']),
        ], static fn($v) => $v !== null);
    }

    private static function publishedCount(): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM articles WHERE status = 'published'",
            [],
            0
        );
    }
}
