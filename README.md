# دانشنامه دستور پخت و راهنما

A Persian-language reference site for recipes and practical guides, built to
stay usable during Iranian internet blackouts.

Articles are researched and written by an AI pipeline that can only assert what
its sources say, then reviewed by a person before anything goes public.

## What makes it unusual

**It survives a blackout.** The site is hosted inside Iran, so cutting
international routes does not cut the site. Every asset is self-hosted — no
CDN, no Google Fonts, no third-party JavaScript — so there is nothing foreign
left to fail. A service worker keeps articles readable with no connection at
all. The Content-Security-Policy enforces this rather than trusting it.

**The AI never runs during a page view.** OpenAI and DeepSeek both refuse
Iranian addresses, so the model runs on a VPS abroad at authoring time and the
result is stored in the database. Serving a page is PHP and SQL. The site makes
no outbound request at any point.

**Citations cannot be invented.** DeepSeek's API has no web browsing, so the
worker does the searching and hands the model a fixed, numbered source set.
Every citation is checked against the sources that were actually fetched —
in the worker, and again on the server. A reference to something nobody
fetched is rejected, not merely discouraged.

**No visitor tracking.** Public pages set no cookies at all. There is no
per-visitor row anywhere in the schema. Stored IP addresses are hashes salted
with a key that rotates daily, so they stop being linkable after 24 hours.

## The stack

| Layer | Choice | Why |
|---|---|---|
| Site | PHP 8, no framework, no Composer | cPanel's native runtime; deployment is copying files |
| Database | MariaDB 10.3 | what the host provides |
| Front end | Vanilla ES modules, no build | edit a file, upload it, see the change |
| Worker | Node 20 on a VPS abroad | needs the outside internet, which the host cannot reach |
| Font | Vazirmatn, self-hosted (SIL OFL) | Google Fonts is unreachable in a blackout |

## Layout

```
app/           application code — never web-accessible
  Core/        router, request, response, database, config, page cache
  Domain/      fields, articles, search, publishing, the job queue
  Support/     Persian text, slugs, contents, auto-linking, sanitising, defences
  Views/       Persian RTL templates, and the English LTR admin
public/        the web root
db/migrations/ schema
tools/         migrate, seed, preflight, tests
worker/        the research worker (deployed to the VPS, not the host)
docs/          deployment, runbook, adding a field
```

## Getting started locally

```bash
php tools/migrate.php          # create the schema
php tools/seed.php --fresh     # realistic Persian sample content
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Then open http://127.0.0.1:8080.

```bash
php tools/admin-user.php you@example.com "Your Name"   # then visit /admin
```

## Tests

```bash
php tools/tests/run.php        # 223 assertions, no dependencies
node tools/tests/run-js.js     # PHP/JS parity for Persian text handling
php tools/preflight.php        # check a server can run this
cd worker && node src/index.js --dry-run   # the pipeline, spending nothing
```

The Persian text handling has two implementations — PHP for the server, JS for
the instant-search box. A shared fixture is run against both, because if they
drift the search box suggests articles the server cannot find.

## Documentation

- [docs/DEPLOY.md](docs/DEPLOY.md) — putting it on cPanel, step by step
- [docs/RUNBOOK.md](docs/RUNBOOK.md) — daily operation and what to do when things break
- [docs/ADDING-A-FIELD.md](docs/ADDING-A-FIELD.md) — growing the menu, and the subdomain switch
- [worker/README.md](worker/README.md) — the research pipeline

## For AI assistants

`/llms.txt`, `/.well-known/ai-manifest.json` and `/about-for-ai` describe what
the site covers. They are never rate limited: one cheap request tells an
assistant everything it needs, instead of it crawling thousands of pages.
Article pages carry schema.org `Recipe` / `HowTo` data. Bulk crawling is rate
limited, because the host cannot absorb it.
