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
result is stored in the database. Serving a page is PHP and SQL. The only
outbound request the site ever makes is a sign-in code to a domestic SMS
gateway, which a blackout does not cut.

**Citations cannot be invented.** DeepSeek's API has no web browsing, so the
worker does the searching and hands the model a fixed, numbered source set.
Every citation is checked against the sources that were actually fetched —
in the worker, and again on the server. A reference to something nobody
fetched is rejected, not merely discouraged.

**No visitor tracking.** Public pages set no cookies at all. There is no
per-visitor row anywhere in the schema. Stored IP addresses are hashes salted
with a key that rotates daily, so they stop being linkable after 24 hours.

**Readers can contribute.** Anyone with an Iranian mobile number can sign in
with an SMS code and send a recipe or guide through a structured form — no
HTML, references cited by number and checked like the AI's. An LLM judge
reviews each one for accuracy and food safety; the owner, or a confident
judge, approves it. Phone numbers are never stored, only a keyed hash.

**The design is swappable.** Everything readers see is a theme: logic-less
templates that receive a documented data contract (`/api/v1/schema`,
[docs/UI-CONTRACT.md](docs/UI-CONTRACT.md)) and cannot run code on the
server. A person or a language model can build a new one without reading the
backend, and it is uploaded, checked and previewed from the admin.

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
  Core/        router, request, response, database, config, page cache, themes
  Domain/      fields, articles, composer, submissions, search, publishing, jobs
  Http/        controllers, view models (the UI contract), SEO head
  Support/     Persian text, slugs, contents, auto-linking, sanitising, SMS, defences
  Views/       the English LTR admin and the Persian account pages
public/        the web root
  themes/      what readers see — one folder per theme
db/migrations/ schema
tools/         migrate, seed, preflight, tests
worker/        the research worker (deployed to the VPS, not the host)
docs/          deployment, runbook, the UI contract, adding a field
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

To try contributor sign-in locally, put `'sms' => ['driver' => 'log']` and a
32+ character `security.phone_pepper` in `app/config.local.php` (with
`debug` on). Codes are then written to `var/sms-outbox.log` instead of sent.

## Tests

```bash
php tools/tests/run.php        # no dependencies; uses the dev database when present
node tools/tests/run-js.js     # PHP/JS parity: Persian text, citation check
php tools/theme-check.php      # the live theme against the UI contract
php tools/preflight.php        # check a server can run this
cd worker && node src/index.js --dry-run   # the pipeline, spending nothing
```

Two things have a PHP and a JS implementation — Persian text handling (the
server and the instant-search box) and the citation check (the server and the
worker). Each pair is run against one shared fixture, because if they drift,
search suggests articles the server cannot find, or the worker passes drafts
the server rejects.

## Documentation

- [docs/DEPLOY.md](docs/DEPLOY.md) — putting it on cPanel, step by step
- [docs/RUNBOOK.md](docs/RUNBOOK.md) — daily operation, moderation, themes, and what to do when things break
- [docs/UI-CONTRACT.md](docs/UI-CONTRACT.md) — everything needed to build a new theme
- [docs/ADDING-A-FIELD.md](docs/ADDING-A-FIELD.md) — growing the menu, and the subdomain switch
- [worker/README.md](worker/README.md) — the research pipeline

## For AI assistants

`/llms.txt`, `/.well-known/ai-manifest.json` and `/about-for-ai` describe what
the site covers. They are never rate limited: one cheap request tells an
assistant everything it needs, instead of it crawling thousands of pages.
Article pages carry schema.org `Recipe` / `Article` data with their citations. Bulk crawling is rate
limited, because the host cannot absorb it.
