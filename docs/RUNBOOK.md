# Runbook

Day-to-day operation, and what to do when something breaks.

## The shape of the system

```
  VPS abroad (Node)            cPanel host in Iran (PHP)        readers
  ─────────────────            ─────────────────────────        ───────
  search, fetch,    ──pull──▶  the queue + content + admin      Persian site
  DeepSeek, images  ──push──▶  drafts land unpublished          static HTML
                               you review and publish           PWA offline
```

The site never makes an outbound connection. That is deliberate: the host
needs no internet access, the API key never lives on shared hosting, and a
page view never depends on a service that an international blackout would cut
off.

## Publishing an article

1. **Admin → Articles → Commission a topic.** Pick the field and the kind.
2. The worker picks the job up within a minute or two. Watch **Dashboard →
   Recent pipeline activity**.
3. The draft appears under **Awaiting your review**.
4. Open it. The Persian draft is on one side, the sources on the other. Click
   any citation marker to jump to the text supporting that claim.
5. Read the validator findings at the top:
   - **error** — the draft cites something that was never fetched. Fix or
     delete the claim. Do not publish past this without a reason.
   - **warning** — usually a figure that does not appear in the source cited
     for that paragraph. Check it against the source pane.
6. Edit if needed, then **Publish**.

Publishing sanitises the HTML, rebuilds the contents, adds internal links,
reindexes it for search, updates the counters, and clears the affected cached
pages. It also re-links older articles that mention the new topic, so the wiki
gets denser on its own.

## Routine checks

| How often | What |
|---|---|
| When publishing | Validator findings on each draft |
| Weekly | Dashboard: failed jobs, estimated spend |
| Monthly | `php tools/preflight.php`; check JetBackup has recent backups |
| After any template change | Flush the page cache from Admin → Settings |

## Common situations

### The worker shows "Silent"

It means no job has moved in an hour. Check the VPS:

```bash
systemctl status recipe-worker
journalctl -u recipe-worker -n 50
```

Most often: the API key expired, the account ran out of credit, or the VPS
rebooted and the service was not enabled. `systemctl enable recipe-worker`
fixes the last one permanently.

### Jobs stuck in "leased"

They fix themselves. A lease expires after fifteen minutes and the job returns
to the queue. If a job has burned through all its attempts it stops as
`failed` and waits for you; open it and read the pipeline log.

### A draft fails citation validation repeatedly

The model is asserting things the sources do not support. Usually the sources
are weak rather than the model being wrong. Look at what was fetched — if the
research found blog posts instead of authorities, reword the topic to be more
specific and commission it again.

### The site is slow

Check the page cache first: **Admin → Settings** shows how many pages are
cached. If the number is near zero, cached pages are not being written —
check that `public_html/cache/pages` is writable.

A cache hit never starts PHP, so a warm site should be almost entirely static.

### A reader says they are being asked to wait

That is the proof-of-work challenge. It fires on a rate limit, and Iranian
mobile carriers put many readers behind one address, so it can catch real
people. If it happens often, raise `security.rate_limit.article.limit` and
`daily_article_cap` in `config.local.php`.

**Admin → Settings → Clear all blocks** lifts everything immediately.

### Persian text shows as boxes or question marks

Boxes mean the font did not load — check `public_html/assets/fonts/`.
Question marks mean the database is not `utf8mb4`; that has to be fixed at the
database level and the content re-imported.

### Restoring after a bad publish

Every publish snapshots the previous version into `article_versions`. There is
no restore button yet; recover with SQL:

```sql
SELECT version, created_at FROM article_versions WHERE article_id = 42;
SELECT snapshot FROM article_versions WHERE article_id = 42 AND version = 3;
```

The snapshot is the full row as JSON.

## Backups

The host runs JetBackup with daily database backups. That covers the database
but **not** `public_html/media`, which holds every generated image. Download
that directory periodically — cPanel → File Manager → Compress → Download.

## Costs to watch

- **The VPS** — a fixed monthly amount.
- **The model** — per article. The dashboard shows a running estimate.
  `DAILY_BUDGET_USD` in `worker/.env` caps it.
- **Search** — Tavily has a free tier; a busy month may exceed it.
- **Images** — the largest per-article cost. `IMAGE_PROVIDER=none` turns it off
  entirely and the pipeline skips straight to pushing the draft.

## Things worth knowing

- **MariaDB 10.3** is what the host runs. Local development runs a newer
  version, so avoid `JSON` columns, `INSERT ... RETURNING`, `JSON_TABLE` and
  `ALTER TABLE ... RENAME COLUMN` — they work locally and fail in production.
- **No Composer, no npm on the host.** The site is plain PHP with a hand-written
  autoloader. Deployment is copying files.
- **Nothing loads from another server.** That is checked by `preflight.php`.
  Adding a CDN link would break the site during a blackout, which is the one
  thing the whole design is built to survive.
