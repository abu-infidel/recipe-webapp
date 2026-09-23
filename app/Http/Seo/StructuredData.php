<?php
declare(strict_types=1);

namespace App\Http\Seo;

use App\Core\Config;
use App\Core\Url;

/**
 * schema.org graphs for search engines and assistants.
 *
 * This is what actually gets a page surfaced and cited, and it is far cheaper
 * for a crawler to read than the page itself.
 *
 * Two choices worth knowing about:
 *   - Guides are Article, not HowTo. Google stopped showing HowTo rich
 *     results in 2023; Article still earns features.
 *   - Every article lists its sources as `citation`. It is the most honest
 *     signal this site can give about where its content comes from.
 */
final class StructuredData
{
    public static function website(): array
    {
        return [
            '@context'      => 'https://schema.org',
            '@type'         => 'WebSite',
            '@id'           => Url::home() . '#website',
            'name'          => Config::string('site.name_fa'),
            'alternateName' => Config::string('site.name_en'),
            'url'           => Url::home(),
            'inLanguage'    => 'fa-IR',
            'publisher'     => ['@id' => Url::home() . '#organization'],
        ];
    }

    public static function organization(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => Url::home() . '#organization',
            'name'     => Config::string('site.name_fa'),
            'url'      => Url::home(),
            'logo'     => [
                '@type'  => 'ImageObject',
                'url'    => Url::base() . '/assets/img/logo-512.png',
                'width'  => 512,
                'height' => 512,
            ],
        ];
    }

    /** @param list<array{title:string,url:string}> $trail home first */
    public static function breadcrumbs(array $trail): array
    {
        $items = [];
        foreach (array_values($trail) as $i => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['title'],
                'item'     => $crumb['url'],
            ];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    public static function collection(array $field, string $url, array $articleUrls): array
    {
        return array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => $field['title_fa'],
            'description' => $field['blurb_fa'] ?: null,
            'url'         => $url,
            'inLanguage'  => 'fa-IR',
            'isPartOf'    => ['@id' => Url::home() . '#website'],
            'hasPart'     => $articleUrls === [] ? null : array_map(
                static fn(string $u) => ['@type' => 'WebPage', 'url' => $u],
                array_slice($articleUrls, 0, 30)
            ),
        ], static fn($v) => $v !== null);
    }

    /**
     * Recipe for recipes, Article for guides and topic pages.
     *
     * @param array|null $hero       ['url','width','height','alt'] absolute
     * @param list<array> $references rows with url + title
     * @param array|null $author     ['name', 'url'?] — null means the site
     */
    public static function article(
        array $article,
        array $field,
        string $url,
        array $recipe,
        ?array $hero,
        array $references,
        ?array $author = null,
    ): array {
        $base = [
            '@context'            => 'https://schema.org',
            'headline'            => mb_substr((string) $article['title_fa'], 0, 110, 'UTF-8'),
            'name'                => $article['title_fa'],
            'description'         => self::plain((string) ($article['summary_fa'] ?? ''), 300),
            'inLanguage'          => 'fa-IR',
            'url'                 => $url,
            'mainEntityOfPage'    => $url,
            'isAccessibleForFree' => true,
            'datePublished'       => self::iso($article['published_at'] ?? null),
            'dateModified'        => self::iso($article['updated_at'] ?? $article['published_at'] ?? null),
            'author'              => $author !== null
                ? array_filter(['@type' => 'Person', 'name' => $author['name'], 'url' => $author['url'] ?? null])
                : ['@id' => Url::home() . '#organization'],
            'publisher'           => ['@id' => Url::home() . '#organization'],
            'isPartOf'            => ['@id' => Url::home() . '#website'],
            'image'               => $hero === null ? null : [
                '@type'  => 'ImageObject',
                'url'    => $hero['url'],
                'width'  => $hero['width'],
                'height' => $hero['height'],
            ],
            'citation'            => self::citations($references),
        ];

        if ($article['kind'] === 'recipe' && $recipe !== []) {
            $yield = isset($recipe['yield_number'])
                ? trim($recipe['yield_number'] . ' ' . ($recipe['yield_unit'] ?? ''))
                : ($recipe['yield'] ?? null);

            return self::compact([
                ...$base,
                '@type'              => 'Recipe',
                'recipeCategory'     => $field['title_fa'],
                'recipeCuisine'      => Config::string('seo.recipe_cuisine', 'ایرانی') ?: null,
                'recipeYield'        => $yield !== '' ? $yield : null,
                'prepTime'           => self::duration($recipe['prep_minutes'] ?? null),
                'cookTime'           => self::duration($recipe['cook_minutes'] ?? null),
                'totalTime'          => self::duration(
                    $recipe['total_minutes'] ?? (((int) ($recipe['prep_minutes'] ?? 0) + (int) ($recipe['cook_minutes'] ?? 0)) ?: null)
                ),
                'recipeIngredient'   => array_values(array_filter(array_map(
                    static fn(array $i) => trim(implode(' ', array_filter([
                        isset($i['quantity']) ? (string) $i['quantity'] : '',
                        $i['unit'] ?? '',
                        $i['name'] ?? '',
                        !empty($i['note']) ? '(' . $i['note'] . ')' : '',
                    ], static fn($p) => $p !== ''))),
                    $recipe['ingredients'] ?? []
                ))),
                'recipeInstructions' => array_values(array_map(
                    static fn(array $s, int $i) => [
                        '@type' => 'HowToStep',
                        'position' => $i + 1,
                        'text' => (string) ($s['text'] ?? ''),
                        'url' => $url . '#step-' . $i,
                    ],
                    $recipe['steps'] ?? [],
                    array_keys($recipe['steps'] ?? [])
                )),
            ]);
        }

        return self::compact([...$base, '@type' => 'Article', 'articleSection' => $field['title_fa']]);
    }

    private static function citations(array $references): ?array
    {
        $out = [];
        foreach ($references as $reference) {
            if (\App\Support\UrlGuard::isHttpUrl($reference['url'] ?? null)) {
                $out[] = array_filter([
                    '@type' => 'CreativeWork',
                    'name'  => $reference['title'] ?: null,
                    'url'   => $reference['url'],
                ]);
            }
        }

        return $out === [] ? null : $out;
    }

    /** ISO 8601 duration in its shortest form: PT45M, PT2H, PT1H30M. */
    public static function duration(mixed $minutes): ?string
    {
        $minutes = (int) $minutes;
        if ($minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT' . ($hours > 0 ? $hours . 'H' : '') . ($rest > 0 ? $rest . 'M' : '');
    }

    private static function iso(mixed $datetime): ?string
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }

        $timestamp = strtotime($datetime);

        return $timestamp === false ? null : date('c', $timestamp);
    }

    private static function plain(string $text, int $length): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $length, 'UTF-8');
    }

    /** Drop nulls and empty lists, recursively enough for these graphs. */
    private static function compact(array $graph): array
    {
        return array_filter($graph, static fn($v) => $v !== null && $v !== [] && $v !== '');
    }
}
