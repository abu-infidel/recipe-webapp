# Adding a new field

A "field" is a branch of the homepage menu — آشپزی, نگهداری مواد غذایی, and so
on. They nest as deep as you like, and the menu adapts on its own: three
children render as large tiles, fifteen render as a filterable list, and you
never touch the layout code.

## The normal case

**Admin → Fields → Add a field.**

- **Title** — Persian, as readers will see it.
- **Parent** — leave at *top level* for a new top-level section.
- **Icon** — one emoji. It is decorative and hidden from screen readers.
- **Accent** — a colour for the tile's edge and its article links.
- **Blurb** — one line on the tile. Optional but worth writing.
- **Visible** — untick to build a section privately first.

That is the whole thing. The menu, the sitemap and `/llms.txt` all pick it up
immediately.

## What happens automatically

- The slug comes from the title, in Persian, and is made unique.
- Article counts roll up: a parent shows the total from everything beneath it.
- `tree.json` is rewritten, so the homepage menu updates without a deploy.
- Unpublishing a field hides everything beneath it, including its articles.

## Moving to subdomains later

The site currently serves fields at paths:

```
https://yourdomain.ir/ashpazi/khoresh
```

The router already understands the subdomain form, so switching is a config
change, not a rebuild:

```php
'routing' => ['mode' => 'subdomain'],
```

`ashpazi.yourdomain.ir/khoresh` then becomes the canonical URL and every
generated link follows. Old path URLs keep working.

**Before you flip it**, create each top-level field as a subdomain in cPanel:

1. cPanel → **Subdomains**
2. Subdomain: the field's slug, e.g. `ashpazi`
3. Document root: **the same `public_html`** as the main site — one codebase
   serves every subdomain; the app routes by hostname
4. cPanel → **SSL/TLS Status** → *Run AutoSSL* so the new name gets a
   certificate

Once a field is a subdomain, adding a new top-level field means doing those
three steps again for it — about a minute. Sub-fields need nothing.

### Why not a wildcard

`*.yourdomain.ir` would mean no cPanel step at all, but free AutoSSL cannot
issue wildcard certificates: those need DNS-01 validation, which needs API
access to your DNS provider, or a paid wildcard certificate. Since fields grow
slowly, one minute per field is the cheaper trade.

## Naming

- The slug is derived from the Persian title, so pick the title first.
- Top-level slugs cannot collide with reserved paths (`admin`, `api`, `search`,
  `assets`, `media`, `llms.txt`…). The admin refuses those with an explanation.
- Renaming a field changes its URLs. A redirect is written automatically, so
  old links keep working, but prefer getting it right the first time.
