<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Database;
use App\Core\Template\SafeHtml;
use App\Core\Template\Theme;
use App\Core\Url;
use App\Domain\Ads;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;
use App\Domain\SearchIndex;
use App\Http\Seo\Head;
use App\Http\Seo\StructuredData;
use App\Support\Jalali;
use App\Support\PersianText;
use App\Support\UrlGuard;

/**
 * The data every public page template receives.
 *
 * This file *is* the UI contract. Whatever a builder returns is exactly what
 * the matching template sees and exactly what /api/v1/page returns, so a theme
 * author can fetch the real data for any URL instead of reading this code.
 * docs/UI-CONTRACT.md documents every key; change a key here and that
 * document, the schema endpoint and the theme checker must change with it.
 *
 * Conventions a theme can rely on:
 *   - every page has `site` and `page`
 *   - `href` is for links on the page (root-relative where possible);
 *     `url` is the absolute canonical form
 *   - numbers a reader sees come with a `_fa` twin in Persian digits
 *   - anything that is HTML is a SafeHtml and renders as HTML; everything
 *     else is escaped, always
 *   - boolean `has_*` / `is_*` flags exist so templates never need logic
 */
final class ViewModels
{
    private const KIND_LABELS = ['recipe' => 'دستور پخت', 'guide' => 'راهنما', 'topic' => 'موضوع'];

    // ================================================================ pages

    public static function home(Theme $theme): array
    {
        $tree = array_map(self::node(...), FieldRepository::tree());

        return [
            ...self::base($theme, 'home', [
                'type'        => 'home',
                'title'       => Config::string('site.name_fa'),
                'description' => Config::string('site.tagline_fa'),
                'canonical'   => Url::home(),
                'json_ld'     => [StructuredData::website(), StructuredData::organization()],
            ]),
            'tree'      => $tree,
            'has_tree'  => $tree !== [],
            // The menu script reads this island; it needs raw JSON, which is
            // why it is SafeHtml (encoded so it cannot close the script tag).
            'tree_json' => new SafeHtml(Head::json(['fields' => $tree])),
        ];
    }

    public static function field(Theme $theme, array $field): array
    {
        $fieldId = (int) $field['id'];
        $url = Url::field((string) $field['path']);
        $childRows = FieldRepository::children($fieldId);
        $children = array_map(static fn(array $row) => self::node([...$row, 'children' => []]), $childRows);

        $articleRows = $childRows === []
            ? ArticleRepository::inField($fieldId, 60)
            : ArticleRepository::inSubtree((string) $field['path'], 24);
        $articles = array_map(static fn(array $row) => self::card($row, (string) ($row['field_path'] ?? $field['path'])), $articleRows);

        $trail = self::trail(FieldRepository::ancestors((string) $field['path']));
        $offline = [Url::href($url), ...array_map(static fn(array $c) => $c['href'], $articles)];

        return [
            ...self::base($theme, 'field', [
                'type'        => 'field',
                'title'       => $field['title_fa'],
                'description' => $field['blurb_fa'] ?: Config::string('site.tagline_fa'),
                'canonical'   => $url,
                'json_ld'     => [
                    StructuredData::breadcrumbs([...$trail, ['title' => $field['title_fa'], 'url' => $url]]),
                    StructuredData::collection($field, $url, array_map(static fn(array $c) => $c['url'], $articles)),
                ],
            ], self::accent($field['accent_color'] ?? null)),
            'field' => [
                'title'  => $field['title_fa'],
                'blurb'  => (string) ($field['blurb_fa'] ?? ''),
                'url'    => $url,
                'href'   => Url::href($url),
                'accent' => self::accent($field['accent_color'] ?? null),
            ],
            'breadcrumbs'      => self::crumbs($trail),
            'children'         => $children,
            'has_children'     => $children !== [],
            'articles'         => $articles,
            'has_articles'     => $articles !== [],
            'articles_heading' => $children === [] ? 'نوشته‌های این بخش' : 'تازه‌ترین نوشته‌ها',
            // JSON as a plain string: it goes in an attribute, where normal
            // escaping is exactly right.
            'offline'          => ['urls_json' => json_encode($offline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'],
            'ads'              => ['footer' => self::ad('field-footer', $fieldId)],
        ];
    }

    public static function article(Theme $theme, array $field, array $article): array
    {
        $articleId = (int) $article['id'];
        $url = Url::article((string) $field['path'], (string) $article['slug']);
        $recipe = self::decode($article['recipe_json'] ?? null);
        $toc = self::decode($article['toc_json'] ?? null);

        // The recipe panel renders its own headings before the body, so they
        // are not in the stored contents, which is built from the body alone.
        if ($article['kind'] === 'recipe' && $recipe !== []) {
            $prefix = [];
            if (!empty($recipe['ingredients'])) {
                $prefix[] = ['id' => 'ingredients', 'text' => 'مواد لازم', 'level' => 0, 'children' => []];
            }
            if (!empty($recipe['steps'])) {
                $prefix[] = ['id' => 'steps', 'text' => 'مراحل پخت', 'level' => 0, 'children' => []];
            }
            $toc = [...$prefix, ...$toc];
        }

        $references = ArticleRepository::references($articleId);
        $hero = self::hero($article);
        $ancestors = FieldRepository::ancestors((string) $field['path']);
        $fieldUrl = Url::field((string) $field['path']);
        $trail = [...self::trail($ancestors), ['title' => $field['title_fa'], 'url' => $fieldUrl]];
        $neighbours = ArticleRepository::neighbours($articleId, (int) $field['id']);
        $author = !empty($article['author_display']) ? ['name' => (string) $article['author_display']] : null;

        return [
            ...self::base($theme, 'article', [
                'type'        => 'article',
                'title'       => $article['title_fa'],
                'description' => (string) ($article['summary_fa'] ?? ''),
                'canonical'   => $url,
                'og_type'     => 'article',
                'image'       => $hero === null ? null : [
                    'url' => $hero['url'], 'width' => $hero['width'], 'height' => $hero['height'], 'alt' => $hero['alt'],
                ],
                'published'   => self::iso($article['published_at'] ?? null),
                'modified'    => self::iso($article['updated_at'] ?? null),
                'section'     => $field['title_fa'],
                'article_id'  => $articleId,
                'json_ld'     => [
                    StructuredData::breadcrumbs([...$trail, ['title' => $article['title_fa'], 'url' => $url]]),
                    StructuredData::article($article, $field, $url, $recipe, $hero, $references, $author),
                ],
            ], self::accent($field['accent_color'] ?? null)),
            'article' => [
                'id'                 => $articleId,
                'title'              => $article['title_fa'],
                'summary'            => (string) ($article['summary_fa'] ?? ''),
                'kind'               => $article['kind'],
                'kind_label'         => self::KIND_LABELS[$article['kind']] ?? '',
                'is_recipe'          => $article['kind'] === 'recipe',
                'url'                => $url,
                'reading_minutes'    => (int) $article['reading_minutes'],
                'reading_minutes_fa' => PersianText::toPersianDigits((string) (int) $article['reading_minutes']),
                'updated_fa'         => Jalali::format((string) ($article['updated_at'] ?? $article['published_at'] ?? '')),
                'author'             => $author['name'] ?? null,
                // Sanitised at publish time; the only HTML an article carries.
                'body'               => new SafeHtml((string) $article['body_html']),
            ],
            'field'           => ['title' => $field['title_fa'], 'url' => $fieldUrl, 'href' => Url::href($fieldUrl)],
            'breadcrumbs'     => self::crumbs($trail),
            'hero'            => $hero,
            'toc'             => self::tocNodes($toc),
            'has_toc'         => count($toc) >= Config::int('content.toc_min_headings', 3),
            'toc_side'        => Config::string('content.toc_side', 'right') === 'left' ? 'left' : 'right',
            'recipe'          => $article['kind'] === 'recipe' && $recipe !== [] ? self::recipe($recipe, $articleId) : null,
            'references'      => array_map(self::reference(...), $references),
            'has_references'  => $references !== [],
            'reference_count_fa' => PersianText::toPersianDigits((string) count($references)),
            'related'         => $related = array_map(
                static fn(array $row) => self::card($row, (string) $row['field_path']),
                ArticleRepository::related($articleId, (int) $field['id'])
            ),
            'has_related'     => $related !== [],
            'neighbours'      => [
                'previous' => self::neighbour($neighbours['previous'], (string) $field['path']),
                'next'     => self::neighbour($neighbours['next'], (string) $field['path']),
            ],
            'has_neighbours'  => $neighbours['previous'] !== null || $neighbours['next'] !== null,
            'ads'             => [
                'top'     => self::ad('article-top', (int) $field['id']),
                'bottom'  => self::ad('article-bottom', (int) $field['id']),
                'sidebar' => self::ad('sidebar', (int) $field['id']),
            ],
        ];
    }

    public static function search(Theme $theme, string $query, array $results, int $page, bool $hasMore): array
    {
        $items = [];
        foreach ($results as $row) {
            $url = Url::article((string) $row['field_path'], (string) $row['slug']);
            $items[] = [
                'title'      => $row['title_fa'],
                'url'        => $url,
                'href'       => Url::href($url),
                'path_label' => str_replace('/', ' ← ', (string) $row['field_path']),
                // Escaped first, then <mark> added — see SearchIndex::snippet.
                'snippet'    => new SafeHtml(SearchIndex::snippet((string) ($row['summary_fa'] ?? ''), $query)),
                'kind_label' => self::KIND_LABELS[$row['kind']] ?? '',
            ];
        }

        $searchUrl = Url::href(Url::search($query));

        return [
            ...self::base($theme, 'search', [
                'type'    => 'search',
                'title'   => $query === '' ? 'جست‌وجو' : 'جست‌وجو: ' . $query,
                'noindex' => true,
            ]),
            'query'            => $query,
            'has_query'        => $query !== '',
            'results'          => $items,
            'has_results'      => $items !== [],
            'result_count_fa'  => PersianText::toPersianDigits((string) count($items)),
            'pagination'       => [
                'previous_href' => $page > 1 ? $searchUrl . '&page=' . ($page - 1) : null,
                'next_href'     => $hasMore ? $searchUrl . '&page=' . ($page + 1) : null,
            ],
        ];
    }

    public static function error(Theme $theme, int $code, string $headline, string $message): array
    {
        return [
            ...self::base($theme, 'error', ['type' => 'error', 'title' => $headline, 'noindex' => true]),
            'error' => [
                'code'     => $code,
                'code_fa'  => PersianText::toPersianDigits((string) $code),
                'headline' => $headline,
                'message'  => $message,
            ],
        ];
    }

    public static function offline(Theme $theme): array
    {
        return self::base($theme, 'offline', ['type' => 'offline', 'title' => 'آفلاین', 'noindex' => true]);
    }

    public static function about(Theme $theme): array
    {
        $total = (int) Database::value("SELECT COUNT(*) FROM articles WHERE status = 'published'", [], 0);

        return [
            ...self::base($theme, 'about', [
                'type'        => 'about',
                'title'       => 'درباره این سایت',
                'description' => 'این سایت چه چیزی ارائه می‌کند، برای خوانندگان و دستیارهای هوش مصنوعی.',
                'canonical'   => Url::base() . '/about-for-ai',
            ]),
            'total'    => $total,
            'total_fa' => PersianText::toPersianDigits((string) $total),
            'tree'     => array_map(self::node(...), FieldRepository::tree()),
        ];
    }

    public static function challenge(Theme $theme, string $nonce, int $difficulty, int $retryAfter): array
    {
        return [
            ...self::base($theme, 'challenge', ['type' => 'challenge', 'title' => 'یک لحظه…', 'noindex' => true],
                null, ['/assets/core/challenge.js']),
            'challenge' => [
                'nonce'            => $nonce,
                'difficulty'       => $difficulty,
                'retry_minutes_fa' => PersianText::toPersianDigits((string) max(1, (int) ceil($retryAfter / 60))),
            ],
        ];
    }

    // =========================================================== building blocks

    public static function site(): array
    {
        return [
            'name'       => Config::string('site.name_fa'),
            'name_en'    => Config::string('site.name_en'),
            'short_name' => Config::string('site.short_name_fa', Config::string('site.name_fa')),
            'tagline'    => Config::string('site.tagline_fa'),
            'url'        => Url::home(),
            'href'       => '/',
            'lang'       => 'fa',
            'dir'        => 'rtl',
            'locale'     => 'fa-IR',
            'search_href'  => '/search',
            'about_href'   => '/about-for-ai',
            'llms_href'    => '/llms.txt',
            'feed_href'    => '/feed.xml',
            'account_href' => '/account',
        ];
    }

    /**
     * site + page, with the core-owned head and foot.
     *
     * @param list<string> $extraCoreScripts same-origin core scripts for this page only
     */
    private static function base(Theme $theme, string $pageType, array $meta, ?string $accent = null, array $extraCoreScripts = []): array
    {
        return [
            'site' => self::site(),
            'page' => [
                'type'        => $pageType,
                'title'       => (string) $meta['title'],
                'description' => (string) ($meta['description'] ?? ''),
                'canonical'   => $meta['canonical'] ?? null,
                'accent'      => $accent,
                'head'        => Head::build($meta, $theme),
                'foot'        => Head::foot($pageType, $theme, $extraCoreScripts),
            ],
        ];
    }

    /** A field as a tree node, recursively. */
    public static function node(array $row): array
    {
        $url = Url::field((string) $row['path']);
        $children = array_map(self::node(...), $row['children'] ?? []);
        $count = (int) ($row['subtree_count'] ?? 0);

        return [
            'slug'             => (string) $row['slug'],
            'path'             => (string) $row['path'],
            'title'            => (string) $row['title_fa'],
            'url'              => $url,
            'href'             => Url::href($url),
            'accent'           => self::accent($row['accent_color'] ?? null),
            'icon'             => isset($row['icon']) && $row['icon'] !== '' ? (string) $row['icon'] : null,
            'article_count'    => $count,
            'article_count_fa' => PersianText::toPersianDigits((string) $count),
            'has_children'     => $children !== [],
            'children'         => $children,
        ];
    }

    public static function card(array $row, string $fieldPath): array
    {
        $url = Url::article($fieldPath, (string) $row['slug']);
        $summary = trim(strip_tags((string) ($row['summary_fa'] ?? '')));

        return [
            'title'              => (string) $row['title_fa'],
            'summary'            => mb_strlen($summary, 'UTF-8') > 140 ? mb_substr($summary, 0, 140, 'UTF-8') . '…' : $summary,
            'url'                => $url,
            'href'               => Url::href($url),
            'kind'               => (string) ($row['kind'] ?? 'guide'),
            'kind_label'         => self::KIND_LABELS[$row['kind'] ?? 'guide'] ?? '',
            'is_recipe'          => ($row['kind'] ?? '') === 'recipe',
            'reading_minutes'    => (int) ($row['reading_minutes'] ?? 1),
            'reading_minutes_fa' => PersianText::toPersianDigits((string) (int) ($row['reading_minutes'] ?? 1)),
        ];
    }

    /** Home plus ancestors, as {title, url}. */
    private static function trail(array $ancestors): array
    {
        $trail = [['title' => 'خانه', 'url' => Url::home()]];
        foreach ($ancestors as $ancestor) {
            $trail[] = ['title' => (string) $ancestor['title_fa'], 'url' => Url::field((string) $ancestor['path'])];
        }

        return $trail;
    }

    /** A trail as template data, each with an href and an is_last flag. */
    private static function crumbs(array $trail): array
    {
        $count = count($trail);

        return array_map(static fn(array $c, int $i) => [
            'title'   => $c['title'],
            'url'     => $c['url'],
            'href'    => Url::href($c['url']),
            'is_last' => $i === $count - 1,
        ], $trail, array_keys($trail));
    }

    private static function tocNodes(array $items): array
    {
        return array_map(static fn(array $item) => [
            'id'           => (string) $item['id'],
            'href'         => '#' . rawurlencode((string) $item['id']),
            'text'         => (string) $item['text'],
            'children'     => self::tocNodes($item['children'] ?? []),
            'has_children' => !empty($item['children']),
        ], $items);
    }

    private static function recipe(array $recipe, int $articleId): array
    {
        $ingredients = [];
        foreach ($recipe['ingredients'] ?? [] as $ingredient) {
            $quantity = $ingredient['quantity'] ?? null;
            $numeric = is_numeric($quantity);

            $ingredients[] = [
                // The scaler multiplies this; only present for numeric amounts.
                'amount_base' => $numeric ? (string) (0 + $quantity) : null,
                'amount_fa'   => $numeric ? PersianText::toPersianDigits((string) (0 + $quantity)) : null,
                'amount_text' => !$numeric && $quantity !== null && $quantity !== '' ? (string) $quantity : null,
                'unit'        => (string) ($ingredient['unit'] ?? ''),
                'name'        => (string) ($ingredient['name'] ?? ''),
                'note'        => (string) ($ingredient['note'] ?? ''),
                'has_note'    => !empty($ingredient['note']),
            ];
        }

        $steps = [];
        foreach (array_values($recipe['steps'] ?? []) as $i => $step) {
            $steps[] = [
                'index'     => $i,
                'number_fa' => PersianText::toPersianDigits((string) ($i + 1)),
                'text'      => (string) ($step['text'] ?? ''),
                'id'        => 'step-' . $i,
            ];
        }

        $yield = isset($recipe['yield_number']) && (int) $recipe['yield_number'] > 0 ? [
            'number'    => (int) $recipe['yield_number'],
            'number_fa' => PersianText::toPersianDigits((string) (int) $recipe['yield_number']),
            'unit'      => (string) ($recipe['yield_unit'] ?? 'نفر'),
        ] : null;

        $minutes = static fn($m) => (int) $m > 0 ? PersianText::toPersianDigits((string) (int) $m) : null;

        return [
            'prep_minutes_fa' => $minutes($recipe['prep_minutes'] ?? null),
            'cook_minutes_fa' => $minutes($recipe['cook_minutes'] ?? null),
            'difficulty'      => (string) ($recipe['difficulty'] ?? ''),
            'yield'           => $yield,
            'ingredients'     => $ingredients,
            'has_ingredients' => $ingredients !== [],
            'steps'           => $steps,
            'has_steps'       => $steps !== [],
            // Where the "steps done" ticks are remembered on the device.
            'storage_key'     => 'steps:' . $articleId,
        ];
    }

    private static function reference(array $row): array
    {
        return [
            'marker'    => (int) $row['marker'],
            'marker_fa' => PersianText::toPersianDigits((string) (int) $row['marker']),
            'anchor'    => 'ref-' . (int) $row['marker'],
            'title'     => (string) ($row['title'] ?: $row['domain']),
            // Null when the stored URL is not plain http(s): render it as text.
            'href'      => UrlGuard::safeHref($row['url'] ?? null),
            'domain'    => (string) $row['domain'],
            'author'    => (string) ($row['author'] ?? ''),
            'date'      => (string) ($row['published_date'] ?? ''),
        ];
    }

    private static function neighbour(?array $row, string $fieldPath): ?array
    {
        if ($row === null) {
            return null;
        }

        $url = Url::article($fieldPath, (string) $row['slug']);

        return ['title' => (string) $row['title_fa'], 'url' => $url, 'href' => Url::href($url)];
    }

    private static function hero(array $article): ?array
    {
        if (empty($article['hero_media_id'])) {
            return null;
        }

        $media = Database::first('SELECT * FROM media WHERE id = :id', ['id' => (int) $article['hero_media_id']]);
        if ($media === null || !UrlGuard::isMediaPath($media['path'] ?? null)) {
            return null;
        }

        $src = '/media/' . $media['path'];

        return [
            'src'          => $src,
            'url'          => Url::base() . $src,
            'width'        => (int) ($media['width'] ?: 1200),
            'height'       => (int) ($media['height'] ?: 675),
            'alt'          => (string) ($media['alt_fa'] ?: $article['title_fa']),
            'caption'      => (string) ($media['caption_fa'] ?? ''),
            'ai_generated' => (bool) $media['is_ai_generated'],
            'srcset'       => self::srcset((string) $media['path']),
        ];
    }

    /**
     * Width variants written by MediaStore alongside the original
     * (name-480.webp, name-960.webp …). Empty when none exist.
     */
    private static function srcset(string $path): string
    {
        $info = pathinfo($path);
        $base = ($info['dirname'] !== '.' ? $info['dirname'] . '/' : '') . preg_replace('/-\d+$/', '', $info['filename']);
        $ext = $info['extension'] ?? 'webp';
        $parts = [];

        foreach ([480, 960, 1600] as $width) {
            $candidate = $base . '-' . $width . '.' . $ext;
            if (is_file(\App\Core\Paths::media() . '/' . $candidate)) {
                $parts[] = '/media/' . $candidate . ' ' . $width . 'w';
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Ad markup is built by core, not by themes: it carries the anonymous
     * counting hooks and, in network mode, the only foreign script the CSP
     * will allow. A theme places {{ads.top}} and styles .ad-slot.
     */
    private static function ad(string $slot, ?int $fieldId): ?SafeHtml
    {
        $creatives = Ads::eligibleCreatives($slot, $fieldId);

        if ($creatives !== []) {
            return new SafeHtml(
                '<aside class="ad-slot" aria-label="آگهی" data-ad-slot="' . htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') . '" hidden>'
                . '<p class="ad-slot__label">آگهی</p>'
                . '<script type="application/json" data-ad-creatives>' . Head::json($creatives) . '</script>'
                . '</aside>'
            );
        }

        $script = Ads::networkScriptFor($slot);
        if ($script !== null) {
            return new SafeHtml(
                '<aside class="ad-slot" aria-label="آگهی" data-ad-slot="' . htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') . '">'
                . '<p class="ad-slot__label">آگهی</p>'
                . '<div data-ad-network="' . htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') . '"></div>'
                . '<script src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '" async></script>'
                . '</aside>'
            );
        }

        return null;
    }

    /** Only a real #rrggbb colour ever reaches a style attribute. */
    private static function accent(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : null;
    }

    private static function iso(mixed $datetime): ?string
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }
        $timestamp = strtotime($datetime);

        return $timestamp === false ? null : date('c', $timestamp);
    }

    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
