<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\View;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;

/**
 * An article page.
 *
 * Everything shown here was computed at publish time — the table of contents,
 * the internal links, the reading time. Rendering is a handful of indexed
 * reads, which is what keeps the site usable on shared hosting.
 */
final class ArticleController
{
    public static function show(Request $request, array $field, array $article): Response
    {
        $articleId = (int) $article['id'];

        $toc = self::decode($article['toc_json']);
        $recipe = self::decode($article['recipe_json']);

        // The recipe panel renders its own headings before the body, so they
        // are absent from the stored contents (which is built from body_html
        // alone). Prepend them, or a reader loses the two sections they are
        // most likely to jump to.
        if ($article['kind'] === 'recipe' && $recipe !== []) {
            $toc = [...self::recipeTocEntries($recipe), ...$toc];
        }

        $html = View::page('public.article', 'public.layout', [
            'article'     => $article,
            'field'       => $field,
            'ancestors'   => FieldRepository::ancestors((string) $field['path']),
            'toc'         => $toc,
            'recipe'      => $recipe,
            'references'  => ArticleRepository::references($articleId),
            'related'     => ArticleRepository::related($articleId, (int) $field['id']),
            'neighbours'  => ArticleRepository::neighbours($articleId, (int) $field['id']),
            'hero'        => self::hero($article),
            'pageTitle'   => $article['title_fa'],
            'description' => self::metaDescription($article),
            'bodyClass'   => 'page-article kind-' . $article['kind'],
            'accent'      => $field['accent_color'] ?? null,
            'canonical'   => Url::article((string) $field['path'], (string) $article['slug']),
            'tocSide'     => Config::string('content.toc_side', 'right'),
            'showToc'     => count($toc) >= Config::int('content.toc_min_headings', 3),
            'jsonLd'      => self::structuredData($article, $field, $recipe),
            'scripts'     => ['/assets/js/article.js'],
        ]);

        // Aggregate counter only. There is no per-visitor record anywhere,
        // which is what lets the site run with no cookies at all.
        ArticleRepository::recordView($articleId);

        return Response::html($html)->cacheFor(1800);
    }

    /**
     * Contents entries for the recipe panel's own headings. The ids match the
     * ones in app/Views/partials/recipe_panel.php.
     *
     * @return list<array{id:string,text:string,level:int,children:list<mixed>}>
     */
    private static function recipeTocEntries(array $recipe): array
    {
        $entries = [];

        if (!empty($recipe['ingredients'])) {
            $entries[] = ['id' => 'ingredients', 'text' => 'مواد لازم', 'level' => 0, 'children' => []];
        }
        if (!empty($recipe['steps'])) {
            $entries[] = ['id' => 'steps', 'text' => 'مراحل پخت', 'level' => 0, 'children' => []];
        }

        return $entries;
    }

    private static function hero(array $article): ?array
    {
        if ($article['hero_media_id'] === null) {
            return null;
        }

        return \App\Core\Database::first(
            'SELECT * FROM media WHERE id = :id',
            ['id' => (int) $article['hero_media_id']]
        );
    }

    private static function metaDescription(array $article): string
    {
        $summary = (string) ($article['summary_fa'] ?? '');
        $summary = trim(strip_tags($summary));

        return mb_substr($summary, 0, 160, 'UTF-8');
    }

    /**
     * Schema.org Recipe / HowTo / Article.
     *
     * This is what actually gets a site surfaced by search engines and cited
     * by assistants, and it is far cheaper for them than crawling the page.
     */
    private static function structuredData(array $article, array $field, array $recipe): array
    {
        $url = Url::article((string) $field['path'], (string) $article['slug']);

        $base = [
            '@context'      => 'https://schema.org',
            'name'          => $article['title_fa'],
            'headline'      => $article['title_fa'],
            'description'   => self::metaDescription($article),
            'inLanguage'    => 'fa-IR',
            'url'           => $url,
            'datePublished' => $article['published_at'] ? date('c', (int) strtotime((string) $article['published_at'])) : null,
            'dateModified'  => $article['updated_at'] ? date('c', (int) strtotime((string) $article['updated_at'])) : null,
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => Config::string('site.name_fa'),
                'url'   => Url::home(),
            ],
        ];

        if ($article['kind'] === 'recipe' && $recipe !== []) {
            return array_filter([
                ...$base,
                '@type'              => 'Recipe',
                'recipeYield'        => $recipe['yield'] ?? null,
                'prepTime'           => self::isoDuration($recipe['prep_minutes'] ?? null),
                'cookTime'           => self::isoDuration($recipe['cook_minutes'] ?? null),
                'totalTime'          => self::isoDuration($recipe['total_minutes'] ?? null),
                'recipeCategory'     => $field['title_fa'],
                'recipeIngredient'   => array_map(
                    static fn(array $i) => trim(($i['quantity'] ?? '') . ' ' . ($i['unit'] ?? '') . ' ' . ($i['name'] ?? '')),
                    $recipe['ingredients'] ?? []
                ) ?: null,
                'recipeInstructions' => array_map(
                    static fn(array $s) => ['@type' => 'HowToStep', 'text' => $s['text'] ?? ''],
                    $recipe['steps'] ?? []
                ) ?: null,
            ], static fn($v) => $v !== null && $v !== []);
        }

        return array_filter([
            ...$base,
            '@type' => $article['kind'] === 'guide' ? 'HowTo' : 'Article',
        ], static fn($v) => $v !== null);
    }

    private static function isoDuration(mixed $minutes): ?string
    {
        $minutes = (int) $minutes;
        if ($minutes <= 0) {
            return null;
        }

        return 'PT' . intdiv($minutes, 60) . 'H' . ($minutes % 60) . 'M';
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
