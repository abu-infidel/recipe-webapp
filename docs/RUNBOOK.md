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

The site makes one kind of outbound connection only: sending a sign-in code
to a domestic SMS gateway when a contributor signs in. Content pages never
call out. That is deliberate: the API keys never live on shared hosting, and
a page view never depends on a service that an international blackout would
cut off.

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

## Writing an article yourself

**Admin → Articles → New recipe / New guide** opens the composer: a form, not
an HTML editor.

- The body is blocks — paragraph, subheading, list, numbered list, tip,
  note, warning, quotation, image — grouped into sections with headings.
  A blank line inside a paragraph starts a new one; `**bold**` is the only
  formatting.
- Add references at the bottom and cite them in the text by number: `[1]`,
  `[۲]`, or several as `[1، 3]`. Paste a sentence from the source into a
  reference's *quote* box when the text states a figure: the citation check
  then verifies the figure against it.
- Images are uploaded as you choose them, re-encoded to WebP at phone-friendly
  sizes, and stripped of metadata (a phone photo's GPS position included).
  Tick *AI-generated* on a lead image that is; readers are told.
- **Save draft** runs the same citation check as the pipeline's drafts and
  shows the findings. Publish from **Review & publish**. Once published, the
  section and address are fixed; editing and saving republishes at once.

Articles from the research pipeline keep their own review screen; the
composer is for articles written in it.

## Contributions

Readers can sign in at `/account` with an SMS code and send recipes and
guides through the same composer. Each submission:

1. is refused at once if it cites a reference that does not exist;
2. is queued for the worker's **judge**, an LLM that reads it and leaves a
   verdict (approve / revise / reject), a score, a food-safety check and a
   list of issues;
3. waits in **Admin → Submissions** (the menu shows how many).

If **Settings → Contributions → the judge may publish** is on, a submission
the judge approves with a score of 80 or more, confirmed food safety, no
major issue and at least one reference is published without waiting for
you, and marked *published by the judge* in the queue. Look through those
now and then. The judge never rejects or returns anything: a false "no"
would quietly turn away a real person, so only you do that.

On a submission you can:

- **Approve and publish**, or **Approve as draft** to polish it in the
  composer first. The contributor's chosen display name becomes the byline.
- **Return for changes** with a note in Persian. They see the note, edit,
  and resend; it goes back through the judge.
- **Reject**, optionally with a note.
- **Suspend the contributor.** They are signed out everywhere and their open
  submissions are closed. Reinstate from the same place.

Stored about a contributor: an HMAC of their phone number (not the number),
their display name, and counts of approved and rejected submissions. Nothing
that could be used to contact them. If they lose access to the number, a new
number is a new account.

## Changing the design

Everything readers see is a **theme** in `public_html/themes/`. The backend
is not involved in how pages look, so a redesign cannot break it.

- **Admin → Themes** lists installed themes with the result of the theme
  check, previews any of them on real pages or sample data, and activates
  one. Activation clears the page cache.
- To install a new theme, upload its `.zip` there. It is unpacked outside
  the web root and checked (no files the server would execute, nothing
  loaded from another origin, the core head and scripts present on every
  page) before anything is installed. It is never activated automatically.
- To have a theme made by a language model, give it the site's address and
  ask it to start from `/api/v1/schema` — or give it `docs/UI-CONTRACT.md`.
  It needs nothing else.
- Readers' browsers may keep pages from the previous theme for up to an hour.
  Keep the old theme installed for a day before deleting it.
- The built-in `default` theme cannot be deleted: it is what the site falls
  back to if the active theme ever breaks.

## Routine checks

| How often | What |
|---|---|
| When publishing | Validator findings on each draft |
| A few times a week | Admin → Submissions, including those *published by the judge* |
| Weekly | Dashboard: failed jobs, estimated spend |
| Monthly | `php tools/preflight.php`; check JetBackup has recent backups |
| After any template change | Flush the page cache from Admin → Settings (activating a theme does this for you) |
| After each deploy | Admin → Settings: apply a database update if one is offered |

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

### Contributors say codes do not arrive

Look in cPanel → **Errors** for "SMS send failed" — the line says why
(credit, template not approved, key wrong) without the key itself. If
many people report it at once, check the gateway's own status page, and
the site-wide hourly ceiling (`otp.global_per_hour`), which returns "busy"
when reached.

### A theme upload is refused

The report lists every problem with the file and line. Common ones: a
Google Fonts or CDN link (download the font into the theme instead), an
`onclick=` attribute (move it into the theme's JS), a missing template, or
`{{page.head}}` / `{{page.foot}}` left out of `layout.mustache`.

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

- **SMS** — one message per sign-in. The limits under `otp` in
  `app/config.php` cap it: one code per number per minute and five a day,
  ten per network address per hour, 300 for the whole site per hour. If your
  credit is small, lower `global_per_hour`.
- **The judge** — one model call per submission and per resubmission.
  `LLM_JUDGE_MODEL` in `worker/.env` can point it at a cheaper model.

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
- **Nothing loads from another server.** That is checked by `preflight.php`
  and, for themes, by the theme check.
  Adding a CDN link would break the site during a blackout, which is the one
  thing the whole design is built to survive.
