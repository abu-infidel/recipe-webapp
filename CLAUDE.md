# Notes for working on this codebase

## Constraints that are not negotiable

**MariaDB 10.3.** That is what the cPanel host runs. Local development runs a
newer version, so these work locally and fail in production — avoid them:
native `JSON` columns (use `LONGTEXT`), `INSERT ... RETURNING`, `JSON_TABLE`,
`ALTER TABLE ... RENAME COLUMN`.

**No Composer, no npm on the host.** The site has no PHP dependencies and no
build step. Deployment is copying files. Adding a dependency means adding a
deployment problem, so do not add one without a reason that survives that
trade.

**Nothing loads from another origin.** No CDN, no Google Fonts, no third-party
script. This is not a preference: the site exists to keep working when
international routes are cut, and one `<script src="https://…">` would break
that. The CSP enforces it and `tools/preflight.php` checks for it.

**No visitor cookies.** Public pages set none. The admin session cookie is
scoped to `/admin`; the contributor session cookie is scoped to `/account` and
set only after SMS sign-in. There is no per-visitor row in the schema and there
should never be one. Per-device conveniences go in `localStorage`, wrapped in
try/catch because it throws in private mode.

**Contributors' phone numbers are never stored.** Only `PhoneNumber::hash()`, an
HMAC under `security.phone_pepper`. Do not write a number to the database, a
log, a URL or an error message. The Iranian mobile range is small enough that
an unpeppered hash would be reversible.

**The site makes one outbound request, and only one.** Sending a sign-in code
to the configured domestic SMS gateway (`app/Support/Sms/`), on the `/account`
sign-in path. Everything else is pulled by the worker. Do not add an API call
to a controller, and never one on a content path.

## Persian text

`app/Support/PersianText.php` is the single source of truth for how Persian is
compared and indexed. `public/assets/core/persian.js` mirrors it, and
`tools/tests/fixtures/persian.json` is run against both. **If you change one,
change the other**, or the instant-search box will suggest articles the server
cannot find.

Things that are easy to get wrong:

- `normalize()` is for indexing and matching. `display()` is what a reader
  sees. Never store `normalize()` output as content — it destroys real
  orthography.
- ZWNJ (U+200C) is meaningful. Preserve it for display; treat it as a word
  boundary for indexing, and index the joined form too, so a reader finds
  "کته‌ای" whether or not they type the half-space.
- Arabic and Persian keyboards produce different characters for the same
  letters (ك/ک, ي/ی). Fold them or search silently fails.
- `normalizeWithMap()` exists because the auto-linker matches on normalised
  text but must splice links onto the original characters. It replays the
  folding by hand; a test keeps it in agreement with `normalize()`.

## Themes and the UI contract

Public pages are rendered by a **theme** (`public/themes/<name>/`): logic-less
Mustache templates plus CSS and JS, swappable from Admin → Themes without
touching the backend. The rules that keep that safe:

- A theme receives only what `app/Http/ViewModels.php` builds. Only a
  `SafeHtml` value is ever output unescaped, and only core creates one.
- `app/Http/UiContract.php` describes every key (served at `/api/v1/schema`).
  **If you add, rename or retype a key in ViewModels, update UiContract** and
  run `php tools/ui-contract-doc.php`; `UiContractTest` fails otherwise, by
  validating real view models and the fixtures against the schema.
- Core owns `{{page.head}}` (SEO, stylesheets) and `{{page.foot}}` (core
  scripts, the scraper trap). The account area is core-rendered *inside* the
  theme's layout (`Page::core`), because its forms carry proof-of-work and
  CSRF hooks a redesign must not break.
- A script hook attribute must name exactly one element. The article wrapper
  once carried `data-toc` too, and `article.js` hid the whole article on
  phones; `ThemeTest` now checks this.
- `tools/theme-check.php` (also run on upload and by preflight) is the gate:
  foreign origins, inline scripts, executable file types, core tags that do
  not render.

## Filesystem paths

The repository's web root is `public/`; cPanel's is `public_html/`. Always go
through `App\Core\Paths` (`public()`, `pages()`, `media()`, `themes()`). A
hard-coded `public/` works locally and silently fails in production.

## Articles written by people

The owner's editor and the contributor form produce an `ArticleComposer`
document (`composer/1`, stored in `body_json`), never HTML. `ArticleComposer`
is the only thing that turns one into HTML, from escaped text and a fixed set
of tags. Do not add a path that accepts HTML from a contributor.

Uploaded images go through `MediaStore`: type from the bytes, dimensions
checked before decoding, re-encoded (which strips EXIF and GPS).

## Where the work happens

Everything expensive happens at **publish time**, never per request:
sanitising, heading ids, the contents tree, internal links, the search index,
the static page cache. A page view should be a handful of indexed reads.

If you find yourself adding work to a request path, check whether it belongs in
`Domain\Publisher` instead.

## The citation guarantee

An article's references are checked mechanically, not trusted:

- The worker hands the model only the numbered sources it fetched.
- `CitationValidator` rejects a citation to a source that is not in that set,
  and flags any figure that does not appear in a source the paragraph itself
  cites.
- It runs in the worker (so a repair round can happen) **and** on the server
  against its own `sources` table. Do not remove the server-side check — a
  check the worker could skip is not a guarantee.
- The two copies (`app/Domain/CitationValidator.php`,
  `worker/src/pipeline/validate.js`) run against one fixture,
  `tools/tests/fixtures/citations.json`. **Change both**, like the Persian
  text functions.
- Figures match as whole numbers, and the °C/°F tolerance applies only to
  values that can be cooking temperatures. Substring matching once let "35"
  be "supported" by any source containing "1".
- Contributor submissions citing a reference that does not exist are refused
  outright. The worker's judge may publish only a confident, clean approval
  (see `Submissions::autoPublishBlocker`); it can never reject.

## Testing

```bash
php tools/tests/run.php        # no dependencies; add to tools/tests/*Test.php
node tools/tests/run-js.js     # PHP/JS parity: Persian text, citation check
php tools/theme-check.php      # the live theme against the UI contract
php tools/preflight.php        # what the host needs, including the above
```

Database-backed tests skip themselves when there is no database, and remove
every row they create.

Verify visually with Playwright against the dev server rather than assuming
markup is right. Several real bugs in this codebase — the `hidden` attribute
losing to a display rule, the header overflowing a phone viewport, a timezone
mismatch between PHP and MariaDB — were only visible in a rendered page.

## Conventions

- Views are plain PHP. Escape every dynamic value with `e()`. Persian inline
  in the English admin needs `<bdi>` or the bidi algorithm reorders the
  English around it.
- Messages a contributor sees are Persian; messages the owner sees are
  English. `ArticleComposer::normalize($input, 'fa')` gives Persian errors.
- Runtime switches the owner can flip live in `settings` via
  `App\Support\Settings` (a stored value overrides config). Add a key to
  `Settings::KEYS` before storing it.
- RTL uses logical properties (`inset-inline-start`, `margin-block`), never
  `left`/`right`.
- The public site is Persian and RTL. The admin is English and LTR. They have
  separate stylesheets on purpose.
- SQL is visible and parameterised. No ORM, no query builder.
