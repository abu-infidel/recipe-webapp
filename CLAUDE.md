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
scoped to `/admin`. There is no per-visitor row in the schema and there should
never be one. Per-device conveniences go in `localStorage`, wrapped in
try/catch because it throws in private mode.

**The site never makes an outbound request.** The worker pulls from it. Do not
add an API call to a controller.

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

## Testing

```bash
php tools/tests/run.php        # no dependencies; add to tools/tests/*Test.php
node tools/tests/run-js.js     # PHP/JS parity
```

Verify visually with Playwright against the dev server rather than assuming
markup is right. Several real bugs in this codebase — the `hidden` attribute
losing to a display rule, the header overflowing a phone viewport, a timezone
mismatch between PHP and MariaDB — were only visible in a rendered page.

## Conventions

- Views are plain PHP. Escape every dynamic value with `e()`. Persian inline
  in the English admin needs `<bdi>` or the bidi algorithm reorders the
  English around it.
- RTL uses logical properties (`inset-inline-start`, `margin-block`), never
  `left`/`right`.
- The public site is Persian and RTL. The admin is English and LTR. They have
  separate stylesheets on purpose.
- SQL is visible and parameterised. No ORM, no query builder.
