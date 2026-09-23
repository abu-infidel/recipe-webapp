<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Template\SafeHtml;
use App\Core\Template\Theme;
use App\Http\Seo\Head;

/**
 * The UI contract, as data.
 *
 * Three things live here and are kept in agreement by tests:
 *
 *   schema()   every key every template receives, typed and described —
 *              served at /api/v1/schema and documented in docs/UI-CONTRACT.md
 *   fixture()  a complete sample model for each page type, needing no
 *              database, so a theme can be checked anywhere
 *   validate() walks a model against the schema and lists every missing,
 *              mistyped or undocumented key
 *
 * ViewModels builds the real models. UiContractTest runs validate() over the
 * fixtures always, and over real models whenever a database is available, so
 * a key added to ViewModels without being documented here fails the suite.
 *
 * Type names used in the schema:
 *   string, int, bool          as in JSON
 *   html                       HTML built and made safe by core. Renders as
 *                              HTML with {{key}}; in JSON it is a string
 *   url                        absolute URL (https://…) — canonical, sharing
 *   href                       a link target for the page: usually
 *                              root-relative ("/ashpazi"), or "#id"
 *   T?                         T or null
 *   list<T>                    array of T
 *   <shape>                    a named shape from `shapes`
 */
final class UiContract
{
    public const API_VERSION = 1;

    // ----------------------------------------------------------------- schema

    public static function schema(): array
    {
        return [
            'api'          => self::API_VERSION,
            'about'        => 'The data contract between this site\'s backend and its themes. A theme is a folder of logic-less Mustache templates plus CSS and JS; it receives exactly the models described here and cannot run code on the server. Everything needed to write a new theme is in this document and the endpoints it lists — the repository is not required.',
            'types'        => [
                'string'  => 'plain text; always HTML-escaped when rendered',
                'int'     => 'integer',
                'bool'    => 'true/false; use with {{#flag}} / {{^flag}}',
                'html'    => 'HTML already made safe by core. {{key}} renders it as HTML. Only core can produce this type.',
                'url'     => 'absolute URL, e.g. https://example.ir/ashpazi — for canonical links and sharing',
                'href'    => 'link target for use on the page: root-relative ("/ashpazi") or a fragment ("#ingredients")',
                'T?'      => 'T or null (null is falsey in sections)',
                'list<T>' => 'array of T; {{#list}}…{{/list}} repeats per item, {{^list}}…{{/list}} renders when empty',
            ],
            'endpoints'    => self::endpoints(),
            'templates'    => [
                'layout'     => [
                    'file'          => 'layout.mustache',
                    'receives'      => 'the page model, plus `content` (html): the rendered page template',
                    'required_tags' => Theme::REQUIRED_LAYOUT_TAGS,
                    'notes'         => 'Put {{page.head}} inside <head> and {{page.foot}} just before </body>. They carry the SEO tags, stylesheets, core scripts and the anti-scraper trap; a theme that leaves them out is rejected.',
                ],
                'pages'      => self::pageTemplates(),
                'partials'   => 'partials/<name>.mustache, included with {{> name}}. Names match ^[a-z0-9][a-z0-9_-/]*$. Partials see the same context as the tag that includes them.',
            ],
            'common_keys'  => self::common(),
            'pages'        => self::pages(),
            'shapes'       => self::shapes(),
            'template_language' => self::templateLanguage(),
            'theme_json'   => self::themeJson(),
            'rules'        => self::rules(),
            'hooks'        => self::hooks(),
            'assets'       => [
                'fonts'        => array_values(array_map('basename', glob(\App\Core\Paths::public() . '/assets/fonts/*.woff2') ?: [])),
                'fonts_base'   => '/assets/fonts/',
                'core_scripts' => 'persian.js and core.js are added by {{page.foot}} on every page; do not list them in theme.json.',
                'theme_base'   => '/themes/<name>/ — list your own CSS and JS in theme.json; the URLs are fingerprinted automatically.',
            ],
        ];
    }

    private static function endpoints(): array
    {
        return [
            ['path' => '/api/v1/schema',             'returns' => 'this document'],
            ['path' => '/api/v1/page?path=/some/url', 'returns' => '{api, page_type, template, status, theme, model}: the exact model the template for that URL receives. path may carry a query, e.g. /search?q=برنج'],
            ['path' => '/api/v1/page?type=error',     'returns' => 'the model for a page type that has no URL of its own (error, challenge, offline)'],
            ['path' => '/api/v1/fixture?type=article', 'returns' => 'a complete, deterministic sample model for any page type — every optional part filled in. The theme checker renders these.'],
            ['path' => '/api/v1/tree',               'returns' => '{api, fields: list<node>}: the published field tree, as home.tree'],
            ['path' => '/api/v1/search?q=…&page=1',  'returns' => '{api, query, results: list<search_result>, has_results, pagination}'],
            ['path' => '/api/v1/theme',              'returns' => 'the active theme: its theme.json and the source of every template, so a redesign can start from what exists'],
            ['path' => '/api/search.json?q=…',       'returns' => 'instant-search suggestions for a header search box: {query, suggestions: [{title, url, …}]}. Callable from theme JS.'],
        ];
    }

    private static function pageTemplates(): array
    {
        return [
            'home'      => ['file' => 'home.mustache',      'url' => '/',                  'purpose' => 'The homepage. It is the menu: the published field tree, nothing else.'],
            'field'     => ['file' => 'field.mustache',     'url' => '/<field path>',      'purpose' => 'A section: its sub-sections, then its articles.'],
            'article'   => ['file' => 'article.mustache',   'url' => '/<field path>/<slug>', 'purpose' => 'A recipe, guide or topic, Wikipedia-style: contents, body, references, related.'],
            'search'    => ['file' => 'search.mustache',    'url' => '/search?q=…',        'purpose' => 'Search results.'],
            'error'     => ['file' => 'error.mustache',     'url' => null,                 'purpose' => 'Any error: 404, 410 (removed), 500.'],
            'offline'   => ['file' => 'offline.mustache',   'url' => '/offline',           'purpose' => 'Shown by the service worker when a page is not saved on the device and there is no connection.'],
            'about'     => ['file' => 'about.mustache',     'url' => '/about-for-ai',      'purpose' => 'What the site offers, for readers and AI assistants.'],
            'challenge' => ['file' => 'challenge.mustache', 'url' => null,                 'purpose' => 'The anti-scraper proof-of-work page. Must keep the data-challenge hooks (see hooks).'],
        ];
    }

    /** Keys on every page. */
    private static function common(): array
    {
        return [
            'site' => ['site', 'Site identity and fixed links.'],
            'page' => ['page', 'This page: title, canonical URL, and the core-owned head and foot.'],
        ];
    }

    private static function pages(): array
    {
        return [
            'home' => [
                'tree'      => ['list<node>', 'Top-level fields, each with its children, recursively.'],
                'has_tree'  => ['bool', 'False on a fresh site with nothing published.'],
                'tree_json' => ['html', 'The tree as JSON ({"fields": list<node>}), encoded so it is safe inside <script type="application/json">. For a menu script.'],
            ],
            'field' => [
                'field' => ['object', 'The field being shown.', [
                    'title'  => ['string', 'Field name.'],
                    'blurb'  => ['string', 'One-line description; may be empty.'],
                    'url'    => ['url', 'Canonical URL.'],
                    'href'   => ['href', 'Link to this page.'],
                    'accent' => ['string?', 'The field\'s colour as #rrggbb, or null.'],
                ]],
                'breadcrumbs'      => ['list<crumb>', 'Home, then each ancestor, then this field.'],
                'children'         => ['list<node>', 'Direct sub-fields (their own children are not included).'],
                'has_children'     => ['bool', ''],
                'articles'         => ['list<card>', 'Articles directly in a leaf field, or the latest from the whole branch.'],
                'has_articles'     => ['bool', ''],
                'articles_heading' => ['string', 'A heading for the article list that matches which of the two it is.'],
                'offline'          => ['object', 'For a "save this section offline" button.', [
                    'urls_json' => ['string', 'JSON array of URLs. Put it in data-urls on an element with data-save-offline; core does the rest.'],
                ]],
                'ads'              => ['object', 'Ad slots on this page, built by core.', [
                    'footer' => ['html?', 'Null when the slot is empty.'],
                ]],
            ],
            'article' => [
                'article' => ['object', 'The article.', [
                    'id'                 => ['int', ''],
                    'title'              => ['string', ''],
                    'summary'            => ['string', 'Lead paragraph; may be empty.'],
                    'kind'               => ['string', 'recipe | guide | topic'],
                    'kind_label'         => ['string', 'kind in Persian.'],
                    'is_recipe'          => ['bool', ''],
                    'url'                => ['url', 'Canonical URL.'],
                    'reading_minutes'    => ['int', ''],
                    'reading_minutes_fa' => ['string', 'reading_minutes in Persian digits.'],
                    'updated_fa'         => ['string', 'Last update as a Persian (Jalali) date.'],
                    'author'             => ['string?', 'Byline for contributed articles; null for the site\'s own.'],
                    'body'               => ['html', 'The article body: p, h2–h4 (with id), lists, tables, figure, blockquote. Citations are <a class="cite" href="#ref-N">; internal links carry data-internal. Allowed classes: cite, note, warning, tip.'],
                ]],
                'field'              => ['object', 'The field the article belongs to.', [
                    'title' => ['string', ''],
                    'url'   => ['url', ''],
                    'href'  => ['href', ''],
                ]],
                'breadcrumbs'        => ['list<crumb>', 'Home, ancestors, then the field. The article itself is not included.'],
                'hero'               => ['hero?', 'The lead image, or null.'],
                'toc'                => ['list<toc_node>', 'Table of contents, nested by heading level.'],
                'has_toc'            => ['bool', 'True when the contents are long enough to be worth showing.'],
                'toc_side'           => ['string', 'right | left — which side the site owner wants the contents on.'],
                'recipe'             => ['recipe?', 'Ingredients, steps and times; null unless kind is recipe.'],
                'references'         => ['list<reference>', 'The sources cited in the body, numbered.'],
                'has_references'     => ['bool', ''],
                'reference_count_fa' => ['string', 'Number of references in Persian digits.'],
                'related'            => ['list<card>', 'Related articles.'],
                'has_related'        => ['bool', ''],
                'neighbours'         => ['object', 'Previous and next article in the same field.', [
                    'previous' => ['neighbour?', ''],
                    'next'     => ['neighbour?', ''],
                ]],
                'has_neighbours'     => ['bool', ''],
                'ads'                => ['object', 'Ad slots, built by core. Each is null when empty.', [
                    'top'     => ['html?', ''],
                    'bottom'  => ['html?', ''],
                    'sidebar' => ['html?', ''],
                ]],
            ],
            'search' => [
                'query'           => ['string', 'What was searched for; empty on the bare search page.'],
                'has_query'       => ['bool', ''],
                'results'         => ['list<search_result>', ''],
                'has_results'     => ['bool', ''],
                'result_count_fa' => ['string', 'Results on this page, in Persian digits.'],
                'pagination'      => ['object', '', [
                    'previous_href' => ['href?', ''],
                    'next_href'     => ['href?', ''],
                ]],
            ],
            'error' => [
                'error' => ['object', '', [
                    'code'     => ['int', 'HTTP status: 404, 410, 500.'],
                    'code_fa'  => ['string', ''],
                    'headline' => ['string', ''],
                    'message'  => ['string', ''],
                ]],
            ],
            'offline' => [],
            'about' => [
                'total'    => ['int', 'Published articles.'],
                'total_fa' => ['string', ''],
                'tree'     => ['list<node>', 'The published field tree.'],
            ],
            'challenge' => [
                'challenge' => ['object', 'Put nonce and difficulty on the data-challenge element; core\'s challenge.js does the work.', [
                    'nonce'            => ['string', ''],
                    'difficulty'       => ['int', ''],
                    'retry_minutes_fa' => ['string', 'For a <noscript> message: how long until the limit lifts.'],
                ]],
            ],
        ];
    }

    private static function shapes(): array
    {
        return [
            'site' => [
                'name'         => ['string', 'Site name in Persian.'],
                'name_en'      => ['string', 'Site name in English.'],
                'short_name'   => ['string', 'Short name for tight spaces.'],
                'tagline'      => ['string', ''],
                'url'          => ['url', 'Homepage.'],
                'href'         => ['href', 'Always "/".'],
                'lang'         => ['string', 'Always "fa".'],
                'dir'          => ['string', 'Always "rtl".'],
                'locale'       => ['string', 'fa-IR'],
                'search_href'  => ['href', 'Search page; a GET form with field q.'],
                'about_href'   => ['href', ''],
                'llms_href'    => ['href', ''],
                'feed_href'    => ['href', 'Atom feed.'],
                'account_href' => ['href', 'Contributor sign-in and submissions.'],
            ],
            'page' => [
                'type'        => ['string', 'The page type (home, field, article, …).'],
                'title'       => ['string', 'This page\'s own title, without the site name.'],
                'description' => ['string', ''],
                'canonical'   => ['url?', ''],
                'accent'      => ['string?', 'Accent colour of the field this page is in, #rrggbb.'],
                'head'        => ['html', 'Everything that belongs in <head>. Required in the layout.'],
                'foot'        => ['html', 'Core scripts and the scraper trap. Required in the layout, before </body>.'],
            ],
            'node' => [
                'slug'             => ['string', ''],
                'path'             => ['string', 'Slash-separated slugs from the root.'],
                'title'            => ['string', ''],
                'url'              => ['url', ''],
                'href'             => ['href', ''],
                'accent'           => ['string?', '#rrggbb'],
                'icon'             => ['string?', 'An icon name, if the owner set one.'],
                'article_count'    => ['int', 'Published articles in this branch.'],
                'article_count_fa' => ['string', ''],
                'has_children'     => ['bool', ''],
                'children'         => ['list<node>', ''],
            ],
            'card' => [
                'title'              => ['string', ''],
                'summary'            => ['string', 'At most ~140 characters.'],
                'url'                => ['url', ''],
                'href'               => ['href', ''],
                'kind'               => ['string', 'recipe | guide | topic'],
                'kind_label'         => ['string', ''],
                'is_recipe'          => ['bool', ''],
                'reading_minutes'    => ['int', ''],
                'reading_minutes_fa' => ['string', ''],
            ],
            'crumb' => [
                'title'   => ['string', ''],
                'url'     => ['url', ''],
                'href'    => ['href', ''],
                'is_last' => ['bool', 'True for the final crumb (the current location).'],
            ],
            'toc_node' => [
                'id'           => ['string', 'The heading\'s id in the body.'],
                'href'         => ['href', '#id, already encoded.'],
                'text'         => ['string', ''],
                'children'     => ['list<toc_node>', ''],
                'has_children' => ['bool', ''],
            ],
            'reference' => [
                'marker'    => ['int', 'The number used in the body (href="#ref-N").'],
                'marker_fa' => ['string', ''],
                'anchor'    => ['string', 'The id this reference must carry: ref-N.'],
                'title'     => ['string', ''],
                'href'      => ['href?', 'Link to the source; null if it is not a plain http(s) URL — render the title as text then.'],
                'domain'    => ['string', ''],
                'author'    => ['string', 'May be empty.'],
                'date'      => ['string', 'As published by the source; may be empty.'],
            ],
            'neighbour' => [
                'title' => ['string', ''],
                'url'   => ['url', ''],
                'href'  => ['href', ''],
            ],
            'hero' => [
                'src'          => ['href', 'Image URL.'],
                'url'          => ['url', ''],
                'width'        => ['int', ''],
                'height'       => ['int', ''],
                'alt'          => ['string', ''],
                'caption'      => ['string', 'May be empty.'],
                'ai_generated' => ['bool', 'If true, label the image as AI-generated.'],
                'srcset'       => ['string', 'Width variants for srcset; may be empty.'],
            ],
            'recipe' => [
                'prep_minutes_fa' => ['string?', ''],
                'cook_minutes_fa' => ['string?', ''],
                'difficulty'      => ['string', 'May be empty.'],
                'yield'           => ['object?', 'Servings; null when unknown.', [
                    'number'    => ['int', ''],
                    'number_fa' => ['string', ''],
                    'unit'      => ['string', ''],
                ]],
                'ingredients'     => ['list<ingredient>', ''],
                'has_ingredients' => ['bool', ''],
                'steps'           => ['list<step>', ''],
                'has_steps'       => ['bool', ''],
                'storage_key'     => ['string', 'localStorage key for remembering ticked steps on this device.'],
            ],
            'ingredient' => [
                'amount_base' => ['string?', 'Numeric amount as a plain number, for a serving scaler. Null when not numeric.'],
                'amount_fa'   => ['string?', 'The same amount in Persian digits.'],
                'amount_text' => ['string?', 'A non-numeric amount ("به‌اندازه لازم"); null when numeric.'],
                'unit'        => ['string', ''],
                'name'        => ['string', ''],
                'note'        => ['string', ''],
                'has_note'    => ['bool', ''],
            ],
            'step' => [
                'index'     => ['int', 'Zero-based.'],
                'number_fa' => ['string', 'One-based, Persian digits.'],
                'text'      => ['string', ''],
                'id'        => ['string', 'step-N; structured data links to it, so keep it as the element id.'],
            ],
            'search_result' => [
                'title'      => ['string', ''],
                'url'        => ['url', ''],
                'href'       => ['href', ''],
                'path_label' => ['string', 'Where the result lives, e.g. "ashpazi ← berenj".'],
                'snippet'    => ['html', 'Summary with the matched words in <mark>.'],
                'kind_label' => ['string', ''],
            ],
        ];
    }

    private static function templateLanguage(): array
    {
        return [
            'engine'   => 'A logic-less Mustache subset. No expressions, no helpers, no lambdas, no set-delimiter.',
            'tags'     => [
                '{{key}} / {{a.b.c}}'   => 'Value, HTML-escaped. An html-typed value renders as HTML.',
                '{{{key}}} / {{&key}}'  => 'Same as {{key}}. Triple braces do NOT unescape plain strings — only html-typed values are ever raw.',
                '{{#key}}…{{/key}}'     => 'Section. A list repeats per item (item becomes the context); an object pushes it as the context; any other truthy value renders once.',
                '{{^key}}…{{/key}}'     => 'Inverted section: renders when key is missing, null, false, 0, "" or an empty list.',
                '{{.}}'                 => 'The current item, inside a section over a list of plain values.',
                '{{> name}}'            => 'Partial from partials/name.mustache. Recursion allowed (depth limit 32).',
                '{{! comment }}'        => 'Ignored.',
            ],
            'lookup'   => 'A name is looked up in the innermost context first, then outward, as in Mustache.',
            'errors'   => 'An unclosed or mismatched section is an error naming the template and line. A page whose theme errors is served with a plain built-in page instead, so a broken theme never takes the site down.',
        ];
    }

    private static function themeJson(): array
    {
        return [
            'name'          => ['string', 'Folder name: ^[a-z0-9][a-z0-9_-]{0,40}$'],
            'title'         => ['string', 'Human name.'],
            'version'       => ['string', ''],
            'api'           => ['int', 'Must be ' . self::API_VERSION . '.'],
            'description'   => ['string', ''],
            'theme_color'   => ['string', '#rrggbb for the browser UI.'],
            'stylesheets'   => ['list<string>', 'Paths inside the theme folder, in load order.'],
            'scripts'       => ['object', 'Page type → list of script paths inside the theme folder. "*" loads on every page.'],
            'preload_fonts' => ['list<string>', 'Font URLs under /assets/fonts/ to preload.'],
            'example'       => [
                'name' => 'mytheme', 'title' => 'My theme', 'version' => '1.0.0', 'api' => self::API_VERSION,
                'stylesheets' => ['theme.css'], 'scripts' => ['*' => ['site.js'], 'article' => ['article.js']],
                'preload_fonts' => ['/assets/fonts/Vazirmatn-Regular.woff2'],
            ],
        ];
    }

    private static function rules(): array
    {
        return [
            'Nothing may load from another origin: no CDN, web font service, analytics or remote image. The site must keep working when international routes are cut. The Content-Security-Policy blocks it anyway, and the theme checker rejects it.',
            'No inline <script> and no on* event attributes (onclick=…): the CSP forbids them. Put behaviour in the theme\'s JS files. style="" attributes are allowed.',
            'The public site is Persian and right-to-left. Use logical CSS properties (margin-inline-start, inset-inline-end, padding-block) instead of left/right.',
            'No cookies. Per-device state goes in localStorage, always wrapped in try/catch because it throws in private browsing.',
            'The layout must include {{page.head}}, {{page.foot}} and {{content}}. Do not add your own <title>, meta description, canonical or JSON-LD — page.head has them.',
            'Every link on the page should be a real <a href>: crawlers and readers without JavaScript must be able to reach every field and article.',
            'Theme JavaScript runs with the site\'s authority. Keep it to presentation; it has no reason to call anything except the endpoints listed here.',
            'Test on a 360px-wide phone. Most readers are on inexpensive Android phones over slow connections: keep CSS and JS small and respect prefers-reduced-motion.',
        ];
    }

    private static function hooks(): array
    {
        return [
            '[data-save-offline][data-urls]' => 'A button. Core saves the listed URLs for offline reading when it is clicked. Field pages supply the list as offline.urls_json.',
            '[data-challenge][data-nonce][data-difficulty]' => 'Challenge page only. Core solves the proof of work and reloads.',
            '[data-challenge-status]' => 'Inside the challenge element; core writes progress text here.',
            '<main id="main">' => 'Expected by the skip link convention and by the service worker\'s offline page.',
            'ads.*' => 'Place the html value where the slot belongs and style .ad-slot / .ad-slot__label. Core fills and counts it.',
        ];
    }

    // ---------------------------------------------------------- documentation

    /**
     * The key reference in docs/UI-CONTRACT.md, generated so it cannot drift.
     * Regenerate with: php tools/ui-contract-doc.php
     */
    public static function markdownReference(): string
    {
        $schema = self::schema();
        $out = [];

        $table = static function (array $fields, string $prefix = '') use (&$table): array {
            $rows = [];
            foreach ($fields as $key => $spec) {
                // Angle brackets would be taken as HTML by a Markdown renderer.
                $description = str_replace(['|', "\n", '<', '>'], ['\\|', ' ', '&lt;', '&gt;'], (string) ($spec[1] ?? ''));
                $rows[] = '| `' . $prefix . $key . '` | `' . str_replace('|', '\\|', $spec[0]) . '` | ' . $description . ' |';
                if (isset($spec[2])) {
                    $rows = [...$rows, ...$table($spec[2], $prefix . $key . '.')];
                }
            }

            return $rows;
        };
        $header = ['| Key | Type | Notes |', '|---|---|---|'];

        $out[] = '### Every page';
        $out[] = '';
        $out = [...$out, ...$header, ...$table($schema['common_keys'])];
        $out[] = '';

        foreach ($schema['pages'] as $page => $fields) {
            $template = $schema['templates']['pages'][$page];
            $out[] = "### `{$page}` — {$template['file']}";
            $out[] = '';
            $out[] = $template['purpose'] . ($template['url'] !== null ? " URL: `{$template['url']}`." : ' Has no URL of its own.');
            $out[] = '';
            if ($fields === []) {
                $out[] = 'Only the keys every page has.';
            } else {
                $out = [...$out, ...$header, ...$table($fields)];
            }
            $out[] = '';
        }

        $out[] = '### Shapes';
        $out[] = '';
        $out[] = 'Named structures used in the tables above.';
        $out[] = '';
        foreach ($schema['shapes'] as $shape => $fields) {
            $out[] = "#### `{$shape}`";
            $out[] = '';
            $out = [...$out, ...$header, ...$table($fields)];
            $out[] = '';
        }

        $out[] = '### Markup hooks core depends on';
        $out[] = '';
        foreach ($schema['hooks'] as $hook => $meaning) {
            $out[] = '- `' . $hook . '` — ' . $meaning;
        }
        $out[] = '';

        $out[] = '### theme.json';
        $out[] = '';
        $out = [...$out, ...$header];
        foreach ($schema['theme_json'] as $key => $spec) {
            if ($key !== 'example') {
                $out[] = '| `' . $key . '` | `' . $spec[0] . '` | ' . $spec[1] . ' |';
            }
        }
        $out[] = '';
        $out[] = '```json';
        $out[] = json_encode($schema['theme_json']['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $out[] = '```';

        return implode("\n", $out) . "\n";
    }

    // --------------------------------------------------------------- fixtures

    /**
     * A complete sample model for a page type. Every optional part is filled
     * in so a template's every branch renders; the head and foot are real,
     * built for the theme being checked.
     */
    public static function fixture(string $pageType, Theme $theme): array
    {
        if (!in_array($pageType, Theme::PAGE_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown page type \"{$pageType}\".");
        }

        $base = Config::string('site.scheme', 'https') . '://' . Config::string('site.domain', 'example.ir');
        $tree = self::sampleTree($base);

        return match ($pageType) {
            'home' => [
                ...self::sampleBase($theme, 'home', 'دستور پخت', $base . '/'),
                'tree'      => $tree,
                'has_tree'  => true,
                'tree_json' => new SafeHtml(Head::json(['fields' => $tree])),
            ],
            'field' => [
                ...self::sampleBase($theme, 'field', 'برنج', $base . '/ashpazi/berenj', '#b4541f'),
                'field' => [
                    'title' => 'برنج', 'blurb' => 'کته، پلو و ته‌دیگ.',
                    'url' => $base . '/ashpazi/berenj', 'href' => '/ashpazi/berenj', 'accent' => '#b4541f',
                ],
                'breadcrumbs'      => self::crumbList($base, [['خانه', '/'], ['آشپزی', '/ashpazi'], ['برنج', '/ashpazi/berenj']]),
                'children'         => [self::sampleNode($base, 'ashpazi/berenj/polo', 'polo', 'پلو', [], 3)],
                'has_children'     => true,
                'articles'         => [self::sampleCard($base), self::sampleCard($base, 'guide')],
                'has_articles'     => true,
                'articles_heading' => 'تازه‌ترین نوشته‌ها',
                'offline'          => ['urls_json' => '["/ashpazi/berenj","/ashpazi/berenj/kate"]'],
                'ads'              => ['footer' => self::sampleAd('field-footer')],
            ],
            'article' => [
                ...self::sampleBase($theme, 'article', 'کته ساده ایرانی', $base . '/ashpazi/berenj/kate', '#b4541f'),
                'article' => [
                    'id' => 1, 'title' => 'کته ساده ایرانی',
                    'summary' => 'کته ساده‌ترین روش پخت برنج ایرانی است.',
                    'kind' => 'recipe', 'kind_label' => 'دستور پخت', 'is_recipe' => true,
                    'url' => $base . '/ashpazi/berenj/kate',
                    'reading_minutes' => 4, 'reading_minutes_fa' => '۴',
                    'updated_fa' => '۱ مهر ۱۴۰۵', 'author' => 'مریم',
                    'body' => new SafeHtml(
                        '<p>کته روشی است که در آن برنج بدون آبکش‌کردن پخته می‌شود.</p>'
                        . '<h2 id="نکته-کلیدی">نکته کلیدی</h2>'
                        . '<p>نسبت آب به برنج تقریباً یک‌ونیم برابر است <a class="cite" href="#ref-1">۱</a>. '
                        . 'برای <a href="/ashpazi/berenj/polo" data-internal>پلو</a> برنج آبکش می‌شود.</p>'
                        . '<div class="tip"><p>در قابلمه را در زمان دم کشیدن باز نکنید.</p></div>'
                        . '<h3 id="ارزش-غذایی">ارزش غذایی</h3>'
                        . '<table><thead><tr><th scope="col">ماده</th><th scope="col">کالری</th></tr></thead>'
                        . '<tbody><tr><td>برنج پخته (۱۰۰ گرم)</td><td>۱۳۰</td></tr></tbody></table>'
                        . '<p>هر صد گرم برنج پخته حدود ۱۳۰ کیلوکالری دارد <a class="cite" href="#ref-2">۲</a>.</p>'
                    ),
                ],
                'field'       => ['title' => 'برنج', 'url' => $base . '/ashpazi/berenj', 'href' => '/ashpazi/berenj'],
                'breadcrumbs' => self::crumbList($base, [['خانه', '/'], ['آشپزی', '/ashpazi'], ['برنج', '/ashpazi/berenj']]),
                'hero' => [
                    'src' => '/assets/img/og-default.png', 'url' => $base . '/assets/img/og-default.png',
                    'width' => 1200, 'height' => 630, 'alt' => 'یک قابلمه کته', 'caption' => 'کته پس از دم کشیدن',
                    'ai_generated' => true, 'srcset' => '',
                ],
                'toc' => [
                    ['id' => 'ingredients', 'href' => '#ingredients', 'text' => 'مواد لازم', 'children' => [], 'has_children' => false],
                    ['id' => 'steps', 'href' => '#steps', 'text' => 'مراحل پخت', 'children' => [], 'has_children' => false],
                    ['id' => 'نکته-کلیدی', 'href' => '#' . rawurlencode('نکته-کلیدی'), 'text' => 'نکته کلیدی', 'has_children' => true, 'children' => [
                        ['id' => 'ارزش-غذایی', 'href' => '#' . rawurlencode('ارزش-غذایی'), 'text' => 'ارزش غذایی', 'children' => [], 'has_children' => false],
                    ]],
                ],
                'has_toc'  => true,
                'toc_side' => 'right',
                'recipe'   => [
                    'prep_minutes_fa' => '۱۰', 'cook_minutes_fa' => '۴۵', 'difficulty' => 'آسان',
                    'yield' => ['number' => 4, 'number_fa' => '۴', 'unit' => 'نفر'],
                    'ingredients' => [
                        ['amount_base' => '3', 'amount_fa' => '۳', 'amount_text' => null, 'unit' => 'پیمانه', 'name' => 'برنج ایرانی', 'note' => '', 'has_note' => false],
                        ['amount_base' => '4.5', 'amount_fa' => '۴.۵', 'amount_text' => null, 'unit' => 'پیمانه', 'name' => 'آب', 'note' => '', 'has_note' => false],
                        ['amount_base' => null, 'amount_fa' => null, 'amount_text' => 'به‌اندازه لازم', 'unit' => '', 'name' => 'نمک', 'note' => 'به‌دلخواه', 'has_note' => true],
                    ],
                    'has_ingredients' => true,
                    'steps' => [
                        ['index' => 0, 'number_fa' => '۱', 'text' => 'برنج را دو بار بشویید.', 'id' => 'step-0'],
                        ['index' => 1, 'number_fa' => '۲', 'text' => 'همه مواد را در قابلمه بریزید و بجوشانید.', 'id' => 'step-1'],
                        ['index' => 2, 'number_fa' => '۳', 'text' => 'سی‌وپنج دقیقه با حرارت ملایم دم بکشد.', 'id' => 'step-2'],
                    ],
                    'has_steps'   => true,
                    'storage_key' => 'steps:1',
                ],
                'references' => [
                    ['marker' => 1, 'marker_fa' => '۱', 'anchor' => 'ref-1', 'title' => 'راهنمای پخت برنج', 'href' => 'https://example.org/rice', 'domain' => 'example.org', 'author' => 'نویسنده نمونه', 'date' => '2024-05-01'],
                    ['marker' => 2, 'marker_fa' => '۲', 'anchor' => 'ref-2', 'title' => 'جدول ارزش غذایی', 'href' => null, 'domain' => 'example.net', 'author' => '', 'date' => ''],
                ],
                'has_references'     => true,
                'reference_count_fa' => '۲',
                'related'            => [self::sampleCard($base, 'guide')],
                'has_related'        => true,
                'neighbours'         => [
                    'previous' => ['title' => 'پلو', 'url' => $base . '/ashpazi/berenj/polo', 'href' => '/ashpazi/berenj/polo'],
                    'next'     => ['title' => 'ته‌دیگ', 'url' => $base . '/ashpazi/berenj/tahdig', 'href' => '/ashpazi/berenj/tahdig'],
                ],
                'has_neighbours' => true,
                'ads' => ['top' => self::sampleAd('article-top'), 'bottom' => null, 'sidebar' => self::sampleAd('sidebar')],
            ],
            'search' => [
                ...self::sampleBase($theme, 'search', 'جست‌وجو: برنج', null),
                'query'           => 'برنج',
                'has_query'       => true,
                'results'         => [[
                    'title' => 'کته ساده ایرانی', 'url' => $base . '/ashpazi/berenj/kate', 'href' => '/ashpazi/berenj/kate',
                    'path_label' => 'ashpazi ← berenj',
                    'snippet' => new SafeHtml('کته ساده‌ترین روش پخت <mark>برنج</mark> ایرانی است.'),
                    'kind_label' => 'دستور پخت',
                ]],
                'has_results'     => true,
                'result_count_fa' => '۱',
                'pagination'      => ['previous_href' => '/search?q=%D8%A8%D8%B1%D9%86%D8%AC&page=1', 'next_href' => '/search?q=%D8%A8%D8%B1%D9%86%D8%AC&page=3'],
            ],
            'error' => [
                ...self::sampleBase($theme, 'error', 'پیدا نشد', null),
                'error' => ['code' => 404, 'code_fa' => '۴۰۴', 'headline' => 'پیدا نشد', 'message' => 'صفحه‌ای که دنبالش بودید اینجا نیست.'],
            ],
            'offline' => self::sampleBase($theme, 'offline', 'آفلاین', null),
            'about' => [
                ...self::sampleBase($theme, 'about', 'درباره این سایت', $base . '/about-for-ai'),
                'total'    => 12,
                'total_fa' => '۱۲',
                'tree'     => $tree,
            ],
            'challenge' => [
                ...self::sampleBase($theme, 'challenge', 'یک لحظه…', null, null, ['/assets/core/challenge.js']),
                'challenge' => [
                    'nonce' => '1800000000.0123456789abcdef.0123456789abcdef0123456789abcdef',
                    'difficulty' => 16,
                    'retry_minutes_fa' => '۱',
                ],
            ],
        };
    }

    private static function sampleBase(Theme $theme, string $type, string $title, ?string $canonical, ?string $accent = null, array $extraCore = []): array
    {
        $meta = ['type' => $type, 'title' => $title, 'description' => 'نمونه', 'canonical' => $canonical, 'noindex' => true];

        return [
            'site' => ViewModels::site(),
            'page' => [
                'type'        => $type,
                'title'       => $title,
                'description' => 'نمونه',
                'canonical'   => $canonical,
                'accent'      => $accent,
                'head'        => Head::build($meta, $theme),
                'foot'        => Head::foot($type, $theme, $extraCore),
            ],
        ];
    }

    private static function sampleTree(string $base): array
    {
        return [
            self::sampleNode($base, 'ashpazi', 'ashpazi', 'آشپزی', [
                self::sampleNode($base, 'ashpazi/berenj', 'berenj', 'برنج', [], 4),
                self::sampleNode($base, 'ashpazi/khoresh', 'khoresh', 'خورش', [], 6),
            ], 10, '#b4541f'),
            self::sampleNode($base, 'negahdari', 'negahdari', 'نگهداری مواد غذایی', [], 0, '#2f855a'),
            self::sampleNode($base, 'rahnama', 'rahnama', 'راهنماهای عملی', [], 3, '#2b6cb0'),
        ];
    }

    private static function sampleNode(string $base, string $path, string $slug, string $title, array $children, int $count, ?string $accent = null): array
    {
        return [
            'slug' => $slug, 'path' => $path, 'title' => $title,
            'url' => $base . '/' . $path, 'href' => '/' . $path,
            'accent' => $accent, 'icon' => null,
            'article_count' => $count, 'article_count_fa' => \App\Support\PersianText::toPersianDigits((string) $count),
            'has_children' => $children !== [], 'children' => $children,
        ];
    }

    private static function sampleCard(string $base, string $kind = 'recipe'): array
    {
        $recipe = $kind === 'recipe';

        return [
            'title' => $recipe ? 'کته ساده ایرانی' : 'نگهداری برنج',
            'summary' => $recipe ? 'کته ساده‌ترین روش پخت برنج ایرانی است.' : 'برنج را در ظرف دربسته و جای خنک نگه دارید.',
            'url' => $base . ($recipe ? '/ashpazi/berenj/kate' : '/negahdari/berenj'),
            'href' => $recipe ? '/ashpazi/berenj/kate' : '/negahdari/berenj',
            'kind' => $kind, 'kind_label' => $recipe ? 'دستور پخت' : 'راهنما', 'is_recipe' => $recipe,
            'reading_minutes' => 4, 'reading_minutes_fa' => '۴',
        ];
    }

    private static function crumbList(string $base, array $items): array
    {
        $last = count($items) - 1;

        return array_map(static fn(array $item, int $i) => [
            'title' => $item[0], 'url' => $base . $item[1], 'href' => $item[1], 'is_last' => $i === $last,
        ], $items, array_keys($items));
    }

    /** What core's ad markup looks like, so a theme can style it. */
    private static function sampleAd(string $slot): SafeHtml
    {
        return new SafeHtml(
            '<aside class="ad-slot" aria-label="آگهی" data-ad-slot="' . $slot . '">'
            . '<p class="ad-slot__label">آگهی</p>'
            . '<a class="ad-slot__creative" href="#"><span>نمونه آگهی</span></a>'
            . '</aside>'
        );
    }

    // ------------------------------------------------------------- validation

    /**
     * Every way a model differs from the schema, as readable strings.
     * An empty list means the model conforms.
     *
     * @return list<string>
     */
    public static function validate(string $pageType, array $model): array
    {
        $pages = self::pages();
        if (!isset($pages[$pageType])) {
            return ["unknown page type \"{$pageType}\""];
        }

        $errors = [];
        self::checkObject([...self::common(), ...$pages[$pageType]], $model, $pageType, $errors);

        return $errors;
    }

    /**
     * Check one value against the whole schema entry for a key. Public so the
     * API tests can check the partial responses (/tree, /search).
     *
     * @return list<string>
     */
    public static function validateValue(string $type, mixed $value, string $where): array
    {
        $errors = [];
        self::checkValue($type, null, $value, $where, $errors);

        return $errors;
    }

    private static function checkObject(array $fields, mixed $value, string $where, array &$errors): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$where}: expected an object, got " . get_debug_type($value);
            return;
        }

        foreach ($fields as $key => $spec) {
            if (!array_key_exists($key, $value)) {
                $errors[] = "{$where}.{$key}: missing";
                continue;
            }
            self::checkValue($spec[0], $spec[2] ?? null, $value[$key], "{$where}.{$key}", $errors);
        }

        foreach (array_keys($value) as $key) {
            if (!isset($fields[$key])) {
                $errors[] = "{$where}.{$key}: not in the schema";
            }
        }
    }

    private static function checkValue(string $type, ?array $inline, mixed $value, string $where, array &$errors): void
    {
        $nullable = str_ends_with($type, '?');
        $type = rtrim($type, '?');

        if ($value === null) {
            if (!$nullable) {
                $errors[] = "{$where}: null but not nullable ({$type})";
            }
            return;
        }

        if (str_starts_with($type, 'list<')) {
            if (!is_array($value) || !array_is_list($value)) {
                $errors[] = "{$where}: expected a list, got " . get_debug_type($value);
                return;
            }
            $inner = substr($type, 5, -1);
            // A long list is represented well enough by its first items.
            foreach (array_slice($value, 0, 25) as $i => $item) {
                self::checkValue($inner, $inline, $item, "{$where}[{$i}]", $errors);
            }
            return;
        }

        if ($type === 'object') {
            self::checkObject($inline ?? [], $value, $where, $errors);
            return;
        }

        $shapes = self::shapes();
        if (isset($shapes[$type])) {
            self::checkObject($shapes[$type], $value, $where, $errors);
            return;
        }

        $ok = match ($type) {
            'string' => is_string($value),
            'int'    => is_int($value),
            'bool'   => is_bool($value),
            'html'   => $value instanceof SafeHtml,
            'url'    => is_string($value) && preg_match('#^https?://#', $value) === 1,
            'href'   => is_string($value) && $value !== '' && preg_match('#^(/|\#|https?://)#', $value) === 1,
            default  => null,
        };

        if ($ok === null) {
            $errors[] = "{$where}: schema uses unknown type \"{$type}\"";
        } elseif (!$ok) {
            $errors[] = "{$where}: expected {$type}, got " . get_debug_type($value)
                . (is_scalar($value) ? ' ' . json_encode($value, JSON_UNESCAPED_UNICODE) : '');
        }
    }
}
