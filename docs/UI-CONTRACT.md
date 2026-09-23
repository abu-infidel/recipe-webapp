# The UI contract (API v1)

Everything a reader sees on this site is a **theme**: a folder of logic-less
templates, CSS and JavaScript. The backend hands each template a fixed,
documented set of data — the *model* — and the theme decides how it looks.

This document is written so that a person or a language model can build a new
theme **without the repository**, and without being able to break anything
behind it. A theme cannot run code on the server, query the database, or output
unescaped HTML. The worst a broken theme can do is fall back to a plain page.

If you are a language model asked to redesign this site: read this file, then
fetch `/api/v1/schema` from the live site. Those two are enough.

---

## 1. What you can and cannot change

| You control | Core controls (you just place it) |
|---|---|
| All markup, via Mustache templates | `{{page.head}}`: title, meta description, canonical, Open Graph, JSON-LD, stylesheets, preloads |
| All CSS | `{{page.foot}}`: core scripts (offline reading, counting, ads) and the scraper trap |
| Your own JavaScript, for presentation | Article bodies (sanitised HTML) and search snippets |
| Layout, typography, colour, motion, the menu | Ad markup (`ads.*`), the challenge page's proof-of-work |
| Which scripts load on which page type | URLs, routing, caching, the service worker |

## 2. The workflow

1. **Read the schema:** `GET /api/v1/schema` — every key every template
   receives, typed and described, plus the rules below in machine-readable form.
2. **See what exists:** `GET /api/v1/theme` — the live theme's `theme.json`
   and the source of every template. Its CSS and JS are at the listed URLs.
3. **Get real data:** `GET /api/v1/page?path=/ashpazi` returns exactly what
   the template for that URL receives. `path` may carry a query
   (`/search?q=برنج`). Pages without a URL: `?type=error`, `?type=challenge`,
   `?type=offline`.
4. **Get complete sample data:** `GET /api/v1/fixture?type=article` — a
   deterministic model for any page type with every optional part filled in
   (hero image, recipe, references, pagination…). Design against these so no
   branch goes untested.
5. **Write the theme** (layout below).
6. **Check it.** With the repository: `php tools/theme-check.php ./mytheme`.
   Without: zip the folder and upload it in **Admin → Themes**, which runs the
   same checks before installing anything.
7. **Preview** any page with the new theme from Admin → Themes (real URLs or
   sample data), then **Activate**. Activation clears the page cache.

A complete, deliberately plain theme that passes every check is in
[`docs/examples/minimal/`](examples/minimal/). Start from it, or from the live
theme via `/api/v1/theme`.

## 3. Folder layout

```
mytheme/
  theme.json            name (= folder name), api: 1, assets per page type
  layout.mustache       the shell around every page
  home.mustache         one template per page type:
  field.mustache          home field article search error offline about challenge
  article.mustache
  search.mustache
  error.mustache
  offline.mustache
  about.mustache
  challenge.mustache
  partials/*.mustache   optional, included with {{> name}}
  *.css *.js            your assets, listed in theme.json
  *.woff2 *.png *.webp *.jpg *.svg …   optional static files
```

Allowed file types: `mustache css js json woff2 png webp jpg jpeg gif ico svg
txt`. Anything else — `.php`, `.html`, `.htaccess`, any hidden file — is refused,
because the folder is served by the web server. SVG files may not contain
script, event handlers or `foreignObject`. Size limit: 5 MB for the whole theme.

The fonts already on the site are at `/assets/fonts/` (listed in the schema
under `assets.fonts`); Vazirmatn is the Persian face. Use them with
`@font-face { src: url('/assets/fonts/…') }`.

## 4. How a page is rendered

1. The router picks the page type and core builds its model.
2. `<type>.mustache` is rendered with the model.
3. `layout.mustache` is rendered with the same model plus `content` — the
   output of step 2, as HTML.

So `layout.mustache` must contain `{{page.head}}` inside `<head>`,
`{{content}}` inside `<main id="main">`, and `{{page.foot}}` just before
`</body>`. The `<html>` element must carry `lang="{{site.lang}}"
dir="{{site.dir}}"`.

```mustache
<!DOCTYPE html>
<html lang="{{site.lang}}" dir="{{site.dir}}">
<head>
{{page.head}}
</head>
<body>
<header><a href="{{site.href}}">{{site.name}}</a></header>
<main id="main">{{content}}</main>
{{page.foot}}
</body>
</html>
```

Do not add your own `<title>`, meta description, canonical link or JSON-LD —
`page.head` has them, and duplicates confuse search engines.

## 5. The template language

A logic-less subset of Mustache. There are no expressions, helpers or lambdas:
the model provides a `has_*` or `is_*` flag for every decision a template
needs to make.

| Tag | Meaning |
|---|---|
| `{{key}}`, `{{a.b.c}}` | The value, HTML-escaped. A value of type `html` renders as HTML. |
| `{{{key}}}`, `{{&key}}` | Identical to `{{key}}`. Triple braces do **not** unescape plain strings. |
| `{{#key}}…{{/key}}` | Section. A list repeats once per item, with the item as context. An object becomes the context. Any other truthy value renders once. |
| `{{^key}}…{{/key}}` | Renders when `key` is missing, `null`, `false`, `0`, `""` or `[]`. |
| `{{.}}` | The current item inside a section over a list of plain values. |
| `{{> name}}` | `partials/name.mustache`, with the current context. May recurse (depth 32) — the field tree is rendered this way. |
| `{{! … }}` | Comment. |

Names are looked up in the innermost context first, then outward. An unclosed
or mismatched section is an error that names the template and line.

**Escaping is not optional.** Only values of type `html` are ever output raw,
and only core can create them. Everything else is escaped however it is
written. This is the whole security boundary for themes.

## 6. Rules

These are checked by `tools/theme-check.php` and by the upload in admin.
Errors block installation; warnings are advice.

- **Nothing loads from another origin.** No CDN, no web-font service, no
  analytics, no remote images, no `fetch()` to another host. The site exists
  to keep working when international links are cut; one foreign `<script>`
  would break that. The Content-Security-Policy blocks it anyway. *(error)*
  Plain `<a href="https://…">` links are navigation, not loads, and are fine.
- **No inline script and no `on*=` attributes.** The CSP forbids both, so they
  would silently do nothing. Put behaviour in a `.js` file listed in
  `theme.json`. JSON data islands (`<script type="application/json">`) are
  fine. `style="…"` attributes are allowed. *(error)*
- **Keep the core tags where they render.** `{{page.head}}` wrapped in a
  section that is empty on some page is caught by rendering every page type.
  *(error)*
- **Keep the challenge hooks** (section 8). Without them a reader who trips
  the rate limit is stuck. *(error)*
- **Right-to-left.** Use logical properties (`margin-inline-start`,
  `padding-block`, `inset-inline-end`, `text-align: start`). Physical
  `left`/`right` is a warning; mark a deliberate one — such as geometric
  centring with `left: 50%` and `translate(-50%)` — with a `/* physical */`
  comment on the same line. *(warning)*
- **No cookies.** Per-device state (a dark-mode choice, ticked recipe steps)
  goes in `localStorage`, always inside `try { … } catch {}` — it throws in
  private browsing. *(warning when `document.cookie` appears)*
- **Real links.** Every field and article must be reachable through a plain
  `<a href>`. Crawlers and readers without JavaScript depend on it; a menu can
  enhance links, never replace them.
- **Small and calm.** Most readers use inexpensive Android phones on slow
  connections. Test at 360 px wide, keep CSS and JS small (over 100 KB per
  file is a warning), and honour `prefers-reduced-motion`.
- **Theme JavaScript runs with the site's authority.** It has no reason to
  touch `/admin` or open connections other than to this site's `/api/`.
  *(warning)*

## 7. Data conventions

- Every page has `site` and `page`.
- `href` is for links on the page (usually root-relative); `url` is the
  absolute canonical form, for sharing.
- Numbers a reader sees come with a `_fa` twin in Persian digits
  (`reading_minutes` → `reading_minutes_fa`). Show the `_fa` one.
- Optional objects are `null` when absent (`hero`, `recipe`, `yield`,
  `neighbours.previous`), so `{{#hero}}…{{/hero}}` both tests and enters them.
- Lists come with a `has_*` flag.
- Article body HTML uses: `p`, `h2`–`h4` (with `id`), lists, `table`,
  `figure`, `blockquote`, `code`, and the classes `cite` (citation markers,
  `<a class="cite" href="#ref-N">`), `note`, `tip` and `warning` (callout
  boxes). Internal links carry `data-internal`. Style these.

## 8. Things core does for you

- **Offline reading.** Put `data-save-offline data-urls="{{offline.urls_json}}"`
  on a button on field pages and core saves that section for offline reading.
  The service worker caches your theme's CSS and JS automatically — list them
  in `theme.json` rather than linking them from templates.
- **The challenge page.** Keep an element with `data-challenge`,
  `data-nonce="{{challenge.nonce}}"` and `data-difficulty="{{challenge.difficulty}}"`,
  containing an element with `data-challenge-status`. Core's script solves the
  proof of work, writes progress into the status element, and reloads.
- **Ads.** Place `{{ads.top}}` etc. where the slots belong and style
  `.ad-slot` and `.ad-slot__label`. Each is `null` when the slot is empty.
- **Search suggestions.** `GET /api/search.json?q=…` returns instant
  suggestions for a header search box, if your theme wants one. The search
  page itself is a plain GET form to `{{site.search_href}}` with field `q`.

## 9. The API

All endpoints are read-only JSON, served from the site itself. `/api/v1/page`
counts against the same rate limits as viewing pages.

| Endpoint | Returns |
|---|---|
| `/api/v1/schema` | The machine-readable form of this document |
| `/api/v1/page?path=/…` | `{api, page_type, template, status, redirect, theme, model}` for a URL |
| `/api/v1/page?type=error\|challenge\|offline` | The same, for pages with no URL |
| `/api/v1/fixture?type=<page type>` | Complete sample model |
| `/api/v1/tree` | `{api, fields: list<node>}` |
| `/api/v1/search?q=…&page=1` | `{api, query, results, has_results, pagination}` |
| `/api/v1/theme` | The live theme's manifest and template sources |

In JSON, `html` values are plain strings. A path that does not exist returns
the error page's model with `status: 404`; a moved one returns
`page_type: "redirect"` with `redirect` set.

## 10. Versioning

This is API **1**, declared in every `theme.json` as `"api": 1`. Keys may be
*added* within API 1 — a theme should ignore keys it does not use. Removing or
renaming a key, or changing its type, means API 2, and a theme declaring 1
would keep working until it is updated.

For maintainers of the backend: the schema lives in `app/Http/UiContract.php`
and the models are built in `app/Http/ViewModels.php`. `UiContractTest` fails
if they disagree, or if the reference below is stale. After changing the
schema run `php tools/ui-contract-doc.php`.

---

## Reference

Generated from the schema. `T?` means T or null; `list<T>` is an array of T;
other names in the Type column are shapes, listed after the page types.

<!-- BEGIN GENERATED REFERENCE: php tools/ui-contract-doc.php -->

### Every page

| Key | Type | Notes |
|---|---|---|
| `site` | `site` | Site identity and fixed links. |
| `page` | `page` | This page: title, canonical URL, and the core-owned head and foot. |

### `home` — home.mustache

The homepage. It is the menu: the published field tree, nothing else. URL: `/`.

| Key | Type | Notes |
|---|---|---|
| `tree` | `list<node>` | Top-level fields, each with its children, recursively. |
| `has_tree` | `bool` | False on a fresh site with nothing published. |
| `tree_json` | `html` | The tree as JSON ({"fields": list&lt;node&gt;}), encoded so it is safe inside &lt;script type="application/json"&gt;. For a menu script. |

### `field` — field.mustache

A section: its sub-sections, then its articles. URL: `/<field path>`.

| Key | Type | Notes |
|---|---|---|
| `field` | `object` | The field being shown. |
| `field.title` | `string` | Field name. |
| `field.blurb` | `string` | One-line description; may be empty. |
| `field.url` | `url` | Canonical URL. |
| `field.href` | `href` | Link to this page. |
| `field.accent` | `string?` | The field's colour as #rrggbb, or null. |
| `breadcrumbs` | `list<crumb>` | Home, then each ancestor, then this field. |
| `children` | `list<node>` | Direct sub-fields (their own children are not included). |
| `has_children` | `bool` |  |
| `articles` | `list<card>` | Articles directly in a leaf field, or the latest from the whole branch. |
| `has_articles` | `bool` |  |
| `articles_heading` | `string` | A heading for the article list that matches which of the two it is. |
| `offline` | `object` | For a "save this section offline" button. |
| `offline.urls_json` | `string` | JSON array of URLs. Put it in data-urls on an element with data-save-offline; core does the rest. |
| `ads` | `object` | Ad slots on this page, built by core. |
| `ads.footer` | `html?` | Null when the slot is empty. |

### `article` — article.mustache

A recipe, guide or topic, Wikipedia-style: contents, body, references, related. URL: `/<field path>/<slug>`.

| Key | Type | Notes |
|---|---|---|
| `article` | `object` | The article. |
| `article.id` | `int` |  |
| `article.title` | `string` |  |
| `article.summary` | `string` | Lead paragraph; may be empty. |
| `article.kind` | `string` | recipe \| guide \| topic |
| `article.kind_label` | `string` | kind in Persian. |
| `article.is_recipe` | `bool` |  |
| `article.url` | `url` | Canonical URL. |
| `article.reading_minutes` | `int` |  |
| `article.reading_minutes_fa` | `string` | reading_minutes in Persian digits. |
| `article.updated_fa` | `string` | Last update as a Persian (Jalali) date. |
| `article.author` | `string?` | Byline for contributed articles; null for the site's own. |
| `article.body` | `html` | The article body: p, h2–h4 (with id), lists, tables, figure, blockquote. Citations are &lt;a class="cite" href="#ref-N"&gt;; internal links carry data-internal. Allowed classes: cite, note, warning, tip. |
| `field` | `object` | The field the article belongs to. |
| `field.title` | `string` |  |
| `field.url` | `url` |  |
| `field.href` | `href` |  |
| `breadcrumbs` | `list<crumb>` | Home, ancestors, then the field. The article itself is not included. |
| `hero` | `hero?` | The lead image, or null. |
| `toc` | `list<toc_node>` | Table of contents, nested by heading level. |
| `has_toc` | `bool` | True when the contents are long enough to be worth showing. |
| `toc_side` | `string` | right \| left — which side the site owner wants the contents on. |
| `recipe` | `recipe?` | Ingredients, steps and times; null unless kind is recipe. |
| `references` | `list<reference>` | The sources cited in the body, numbered. |
| `has_references` | `bool` |  |
| `reference_count_fa` | `string` | Number of references in Persian digits. |
| `related` | `list<card>` | Related articles. |
| `has_related` | `bool` |  |
| `neighbours` | `object` | Previous and next article in the same field. |
| `neighbours.previous` | `neighbour?` |  |
| `neighbours.next` | `neighbour?` |  |
| `has_neighbours` | `bool` |  |
| `ads` | `object` | Ad slots, built by core. Each is null when empty. |
| `ads.top` | `html?` |  |
| `ads.bottom` | `html?` |  |
| `ads.sidebar` | `html?` |  |

### `search` — search.mustache

Search results. URL: `/search?q=…`.

| Key | Type | Notes |
|---|---|---|
| `query` | `string` | What was searched for; empty on the bare search page. |
| `has_query` | `bool` |  |
| `results` | `list<search_result>` |  |
| `has_results` | `bool` |  |
| `result_count_fa` | `string` | Results on this page, in Persian digits. |
| `pagination` | `object` |  |
| `pagination.previous_href` | `href?` |  |
| `pagination.next_href` | `href?` |  |

### `error` — error.mustache

Any error: 404, 410 (removed), 500. Has no URL of its own.

| Key | Type | Notes |
|---|---|---|
| `error` | `object` |  |
| `error.code` | `int` | HTTP status: 404, 410, 500. |
| `error.code_fa` | `string` |  |
| `error.headline` | `string` |  |
| `error.message` | `string` |  |

### `offline` — offline.mustache

Shown by the service worker when a page is not saved on the device and there is no connection. URL: `/offline`.

Only the keys every page has.

### `about` — about.mustache

What the site offers, for readers and AI assistants. URL: `/about-for-ai`.

| Key | Type | Notes |
|---|---|---|
| `total` | `int` | Published articles. |
| `total_fa` | `string` |  |
| `tree` | `list<node>` | The published field tree. |

### `challenge` — challenge.mustache

The anti-scraper proof-of-work page. Must keep the data-challenge hooks (see hooks). Has no URL of its own.

| Key | Type | Notes |
|---|---|---|
| `challenge` | `object` | Put nonce and difficulty on the data-challenge element; core's challenge.js does the work. |
| `challenge.nonce` | `string` |  |
| `challenge.difficulty` | `int` |  |
| `challenge.retry_minutes_fa` | `string` | For a &lt;noscript&gt; message: how long until the limit lifts. |

### Shapes

Named structures used in the tables above.

#### `site`

| Key | Type | Notes |
|---|---|---|
| `name` | `string` | Site name in Persian. |
| `name_en` | `string` | Site name in English. |
| `short_name` | `string` | Short name for tight spaces. |
| `tagline` | `string` |  |
| `url` | `url` | Homepage. |
| `href` | `href` | Always "/". |
| `lang` | `string` | Always "fa". |
| `dir` | `string` | Always "rtl". |
| `locale` | `string` | fa-IR |
| `search_href` | `href` | Search page; a GET form with field q. |
| `about_href` | `href` |  |
| `llms_href` | `href` |  |
| `feed_href` | `href` | Atom feed. |
| `account_href` | `href` | Contributor sign-in and submissions. |

#### `page`

| Key | Type | Notes |
|---|---|---|
| `type` | `string` | The page type (home, field, article, …). |
| `title` | `string` | This page's own title, without the site name. |
| `description` | `string` |  |
| `canonical` | `url?` |  |
| `accent` | `string?` | Accent colour of the field this page is in, #rrggbb. |
| `head` | `html` | Everything that belongs in &lt;head&gt;. Required in the layout. |
| `foot` | `html` | Core scripts and the scraper trap. Required in the layout, before &lt;/body&gt;. |

#### `node`

| Key | Type | Notes |
|---|---|---|
| `slug` | `string` |  |
| `path` | `string` | Slash-separated slugs from the root. |
| `title` | `string` |  |
| `url` | `url` |  |
| `href` | `href` |  |
| `accent` | `string?` | #rrggbb |
| `icon` | `string?` | An icon name, if the owner set one. |
| `article_count` | `int` | Published articles in this branch. |
| `article_count_fa` | `string` |  |
| `has_children` | `bool` |  |
| `children` | `list<node>` |  |

#### `card`

| Key | Type | Notes |
|---|---|---|
| `title` | `string` |  |
| `summary` | `string` | At most ~140 characters. |
| `url` | `url` |  |
| `href` | `href` |  |
| `kind` | `string` | recipe \| guide \| topic |
| `kind_label` | `string` |  |
| `is_recipe` | `bool` |  |
| `reading_minutes` | `int` |  |
| `reading_minutes_fa` | `string` |  |

#### `crumb`

| Key | Type | Notes |
|---|---|---|
| `title` | `string` |  |
| `url` | `url` |  |
| `href` | `href` |  |
| `is_last` | `bool` | True for the final crumb (the current location). |

#### `toc_node`

| Key | Type | Notes |
|---|---|---|
| `id` | `string` | The heading's id in the body. |
| `href` | `href` | #id, already encoded. |
| `text` | `string` |  |
| `children` | `list<toc_node>` |  |
| `has_children` | `bool` |  |

#### `reference`

| Key | Type | Notes |
|---|---|---|
| `marker` | `int` | The number used in the body (href="#ref-N"). |
| `marker_fa` | `string` |  |
| `anchor` | `string` | The id this reference must carry: ref-N. |
| `title` | `string` |  |
| `href` | `href?` | Link to the source; null if it is not a plain http(s) URL — render the title as text then. |
| `domain` | `string` |  |
| `author` | `string` | May be empty. |
| `date` | `string` | As published by the source; may be empty. |

#### `neighbour`

| Key | Type | Notes |
|---|---|---|
| `title` | `string` |  |
| `url` | `url` |  |
| `href` | `href` |  |

#### `hero`

| Key | Type | Notes |
|---|---|---|
| `src` | `href` | Image URL. |
| `url` | `url` |  |
| `width` | `int` |  |
| `height` | `int` |  |
| `alt` | `string` |  |
| `caption` | `string` | May be empty. |
| `ai_generated` | `bool` | If true, label the image as AI-generated. |
| `srcset` | `string` | Width variants for srcset; may be empty. |

#### `recipe`

| Key | Type | Notes |
|---|---|---|
| `prep_minutes_fa` | `string?` |  |
| `cook_minutes_fa` | `string?` |  |
| `difficulty` | `string` | May be empty. |
| `yield` | `object?` | Servings; null when unknown. |
| `yield.number` | `int` |  |
| `yield.number_fa` | `string` |  |
| `yield.unit` | `string` |  |
| `ingredients` | `list<ingredient>` |  |
| `has_ingredients` | `bool` |  |
| `steps` | `list<step>` |  |
| `has_steps` | `bool` |  |
| `storage_key` | `string` | localStorage key for remembering ticked steps on this device. |

#### `ingredient`

| Key | Type | Notes |
|---|---|---|
| `amount_base` | `string?` | Numeric amount as a plain number, for a serving scaler. Null when not numeric. |
| `amount_fa` | `string?` | The same amount in Persian digits. |
| `amount_text` | `string?` | A non-numeric amount ("به‌اندازه لازم"); null when numeric. |
| `unit` | `string` |  |
| `name` | `string` |  |
| `note` | `string` |  |
| `has_note` | `bool` |  |

#### `step`

| Key | Type | Notes |
|---|---|---|
| `index` | `int` | Zero-based. |
| `number_fa` | `string` | One-based, Persian digits. |
| `text` | `string` |  |
| `id` | `string` | step-N; structured data links to it, so keep it as the element id. |

#### `search_result`

| Key | Type | Notes |
|---|---|---|
| `title` | `string` |  |
| `url` | `url` |  |
| `href` | `href` |  |
| `path_label` | `string` | Where the result lives, e.g. "ashpazi ← berenj". |
| `snippet` | `html` | Summary with the matched words in &lt;mark&gt;. |
| `kind_label` | `string` |  |

### Markup hooks core depends on

- `[data-save-offline][data-urls]` — A button. Core saves the listed URLs for offline reading when it is clicked. Field pages supply the list as offline.urls_json.
- `[data-challenge][data-nonce][data-difficulty]` — Challenge page only. Core solves the proof of work and reloads.
- `[data-challenge-status]` — Inside the challenge element; core writes progress text here.
- `<main id="main">` — Expected by the skip link convention and by the service worker's offline page.
- `ads.*` — Place the html value where the slot belongs and style .ad-slot / .ad-slot__label. Core fills and counts it.

### theme.json

| Key | Type | Notes |
|---|---|---|
| `name` | `string` | Folder name: ^[a-z0-9][a-z0-9_-]{0,40}$ |
| `title` | `string` | Human name. |
| `version` | `string` |  |
| `api` | `int` | Must be 1. |
| `description` | `string` |  |
| `theme_color` | `string` | #rrggbb for the browser UI. |
| `stylesheets` | `list<string>` | Paths inside the theme folder, in load order. |
| `scripts` | `object` | Page type → list of script paths inside the theme folder. "*" loads on every page. |
| `preload_fonts` | `list<string>` | Font URLs under /assets/fonts/ to preload. |

```json
{
    "name": "mytheme",
    "title": "My theme",
    "version": "1.0.0",
    "api": 1,
    "stylesheets": [
        "theme.css"
    ],
    "scripts": {
        "*": [
            "site.js"
        ],
        "article": [
            "article.js"
        ]
    },
    "preload_fonts": [
        "/assets/fonts/Vazirmatn-Regular.woff2"
    ]
}
```

<!-- END GENERATED REFERENCE -->
