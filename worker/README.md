# Research worker

Runs on a small VPS **outside Iran**. OpenAI and DeepSeek both refuse Iranian
addresses, so the AI cannot run on the cPanel host — and it does not need to.

The worker pulls jobs from the site, does everything that needs the outside
internet, and pushes finished Persian drafts back over HTTPS. **The site never
makes an outbound connection.** That means the shared host needs no internet
access at all, and the API key never lives on it.

```
  this VPS                               the site (cPanel, Iran)
  ────────                               ───────────────────────
  search the web        ──── pull ────▶  GET  a queued job
  fetch and extract
  DeepSeek synthesis
  citation validator    ──── push ────▶  POST the finished draft
  Persian rendering                      lands as a DRAFT, never published
  image generation                       you review it in the admin panel
```

## Why it works this way

DeepSeek's API has **no web browsing**. "Deep research mode" is not a flag we
can turn on — the worker does the searching and page-fetching itself, then
hands the model a fixed, numbered set of sources and forbids it from citing
anything outside that set.

This is better than asking the model to behave. A citation to a source that
was never fetched is **mechanically rejected**, both here and again on the
server, so a hallucinated reference cannot reach a published page.

## Requirements

- Node.js 20 or newer
- 1 vCPU, 1–2 GB RAM is plenty (about $5/month)
- Outbound HTTPS to your site, the model API, and the search API

## Setup

```bash
git clone <your repo> recipe-webapp
cd recipe-webapp/worker
npm install                 # optional: improves source extraction
cp .env.example .env
nano .env                   # fill in the keys
```

Generate the site token **on the cPanel host**, not here:

```bash
php tools/worker-token.php "vps-paris"
```

It prints the token once. Put it in `.env` as `SITE_TOKEN`, and make
`SITE_HMAC_SECRET` match `worker.hmac_secret` in the site's
`app/config.local.php`.

Then set the VPS's public IP in the site's config:

```php
'worker' => ['ip_allowlist' => ['203.0.113.10']],
```

## Running

```bash
npm run once                        # handle one job and stop
npm start                           # poll forever
node src/index.js --dry-run         # no API calls, nothing spent
```

The dry run exercises the whole chain against recorded fixtures containing two
deliberate defects — an invented citation and an unsupported number — and
fails if the validator misses either. Run it after any change to the prompts.

### As a service

```ini
# /etc/systemd/system/recipe-worker.service
[Unit]
Description=Recipe research worker
After=network-online.target

[Service]
Type=simple
User=worker
WorkingDirectory=/home/worker/recipe-webapp/worker
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=30
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now recipe-worker
journalctl -u recipe-worker -f
```

## The pipeline

| Stage | What it does |
|---|---|
| `plan` | Topic → outline plus specific, searchable research questions |
| `search` | Each question through the search provider; content farms dropped |
| `fetch` | Download and extract with Readability; stored as numbered sources |
| `synthesize` | Model sees **only** the sources; every paragraph carries its refs |
| `validate` | Mechanical check, then a repair round, then the server's own check |
| `persian` | English → Persian, with citation markers counted before and after |
| `image` | Optional hero image, labelled as AI-generated on the page |
| `push` | Lands as a **draft** for you to review |

Each stage is a separate job, so a failure retries only its own step instead
of throwing away the research.

## Configuration

Everything is in `.env`; nothing here is DeepSeek-specific.

- `LLM_BASE_URL` / `LLM_MODEL` — any OpenAI-compatible endpoint. DeepSeek ships
  `deepseek-chat` and `deepseek-reasoner`; there is no "flash" model (that is
  Google's naming).
- `SEARCH_PROVIDER` — `tavily`, `brave`, `searxng` or `none`.
- `IMAGE_PROVIDER` — `openai` or `none`. With `none` the pipeline skips
  straight to pushing the draft.
- `DAILY_BUDGET_USD` — a ceiling, so a runaway loop cannot empty the account.

## If something goes wrong

- **`unauthorized`** — the token, the HMAC secret, or the IP allowlist. The
  server logs which of the three refused; the response deliberately does not.
- **`Citation validation failed on the server`** — working as intended. The
  model cited something that is not in the sources table. Check the job's
  events in the admin panel.
- **`Only N source(s) could be read`** — sites refused the fetch. Tavily's
  `include_raw_content` avoids most of this; check the search provider first.
- **Jobs stuck in `leased`** — a worker died. Leases expire by themselves and
  the job returns to the queue; nothing needs resetting by hand.
