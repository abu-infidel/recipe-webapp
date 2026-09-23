# Deploying to cPanel

Written for someone who has not done this before. Nothing here needs SSH,
though it is faster if you have it.

## Before you start

You need the cPanel login from your host, and a domain pointed at it.

## 1. Create the database

cPanel → **MySQL Databases**.

1. Under *Create New Database*, name it `recipes`. cPanel prefixes it with your
   account name, so the real name ends up like `myaccount_recipes` — write that
   down.
2. Under *Add New User*, create a user and a **long random password**. Write
   both down.
3. Under *Add User To Database*, add the user to the database and tick
   **ALL PRIVILEGES**.

## 2. Get the files onto the server

### Option A — Git (better, if GitHub is reachable)

cPanel's **Git Version Control** can pull this repository and deploy it with
`.cpanel.yml`. Two things to know first.

**The repository is private, so the server needs a deploy key.** A deploy key
is an SSH key that can read this one repository and nothing else.

1. cPanel → **SSH Access** → *Manage SSH Keys* → *Generate a New Key*. Leave
   the passphrase empty (cPanel's Git cannot type one), name it `github-deploy`.
2. In the key list, *Manage* → **Authorize**, then *View/Download* the
   **public** key and copy it.
3. GitHub → the repository → **Settings → Deploy keys → Add deploy key**.
   Paste it, leave *Allow write access* **unticked**.

If your plan has no SSH Access page, use Option B.

**GitHub may be slow or unreachable from Iran.** Iranian routes to GitHub are
throttled or cut from time to time. If *Update from Remote* hangs or fails,
nothing is broken — use Option B for that update and try Git again later.
The site itself never depends on GitHub; only deploying does.

Then:

1. Edit `.cpanel.yml` in the repository and replace **both** `USERNAME`s with
   your cPanel account name. Commit and push that change.
2. cPanel → **Git Version Control** → *Create*:
   - Tick **Clone a Repository**
   - Clone URL: `git@github.com:abu-infidel/recipe-webapp.git` (the SSH form,
     so the deploy key is used)
   - Repository path: `repositories/recipe-webapp`
3. **Manage** → **Pull or Deploy** → *Update from Remote*, then *Deploy HEAD
   Commit*. The deploy copies `public/` into `public_html/` and `app/`, `db/`,
   `tools/` into your home directory, above the web root.

### Option B — Zip upload

On your own machine, zip the project. In cPanel → **File Manager**:

1. Upload the zip to your home directory and extract it.
2. Move the **contents of** `public/` into `public_html/`.
3. Move `app/`, `db/` and `tools/` into your home directory — **not** into
   `public_html`. They must not be reachable over the web.

Your home directory should end up looking like this:

```
/home/youraccount/
├── app/            ← application code, NOT web-accessible
├── db/
├── tools/
└── public_html/    ← the web root
    ├── index.php
    ├── .htaccess
    └── assets/
```

## 3. Configure

File Manager → `app/` → copy `config.local.php.example` to
`config.local.php`, then edit it:

```php
return [
    'site' => ['domain' => 'yourdomain.ir', 'scheme' => 'https'],
    'db' => [
        'host' => 'localhost',
        'name' => 'myaccount_recipes',
        'user' => 'myaccount_recipes',
        'pass' => 'the password you wrote down',
    ],
    'security' => [
        'app_key'      => 'PASTE 64 RANDOM HEX CHARACTERS HERE',
        'phone_pepper' => 'PASTE 64 MORE, DIFFERENT ONES',
    ],
    'worker'   => ['hmac_secret' => 'PASTE 64 DIFFERENT RANDOM HEX CHARACTERS'],
    'sms'      => ['driver' => 'kavenegar', 'kavenegar' => ['api_key' => '', 'template' => '']],
    'debug'    => false,
];
```

`phone_pepper` keys the stored form of contributors' phone numbers. Set it
once and never change it: a new pepper makes every contributor a stranger.
The `sms` part is filled in at step 9; until then the site works and only
contributor sign-in is off.

For the random values, use cPanel's **Password Generator**, or in Terminal:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**Never commit this file.** It is already in `.gitignore`.

## 4. Set the PHP version

cPanel → **MultiPHP Manager** → select your domain → set **PHP 8.1 or newer**.

## 5. Create the tables

With Terminal (cPanel → *Advanced* → **Terminal**):

```bash
cd ~ && php tools/migrate.php && php tools/preflight.php
```

**Without Terminal**, run the same command once as a cron job: cPanel →
**Cron Jobs** → *Common Settings: Once Per Minute*, command
`cd ~ && php tools/migrate.php`, *Add*. The output is emailed to the address
at the top of that page. When it arrives (a minute or two), **delete the cron
job**. Do the same with `php tools/preflight.php` to see the checks.

`preflight.php` checks everything that commonly goes wrong — extensions,
permissions, the database clock, whether anything loads from a foreign server,
whether the live theme passes its checks. Fix anything it marks FAIL.

## 6. Create your admin account

With Terminal:

```bash
php tools/admin-user.php you@example.com "Your Name"
```

It asks for a password (12+ characters). Without Terminal, use a one-off cron
job again with `--generate`, which makes a strong password and prints it into
the emailed output:

```
cd ~ && php tools/admin-user.php you@example.com "Your Name" --generate
```

Delete the cron job straight after, keep the password in a password manager,
and sign in at `https://yourdomain.ir/admin`.

Later database changes need no Terminal at all: when an update ships one,
**Admin → Settings** shows *Database update needed* with an **Apply** button.

## 7. Turn on HTTPS

cPanel → **SSL/TLS Status** → select the domain → *Run AutoSSL*. It takes a
few minutes.

Once the certificate is live, edit `public_html/.htaccess` and uncomment the
three HTTPS redirect lines near the top.

## 8. Set up the research worker

See [worker/README.md](../worker/README.md). It runs on a VPS **outside Iran**,
because OpenAI and DeepSeek both refuse Iranian addresses.

On the cPanel host, issue it a token:

```bash
php tools/worker-token.php "vps-paris"
```

Then put the VPS's IP in `config.local.php`:

```php
'worker' => ['ip_allowlist' => ['203.0.113.10']],
```

## 9. Contributor sign-in by SMS

Readers who want to send articles sign in with a code sent by SMS. The site
supports three domestic gateways; pick one. All three need an **OTP template**
approved in their panel before codes can be sent — templated messages use the
priority route and are not caught by the advertising-SMS filter.

| Gateway | In its panel | In `config.local.php` |
|---|---|---|
| **Kavenegar** (default) | *Verify lookup* template whose text contains `%token%`, e.g. `کد ورود شما: %token%` | `'sms' => ['driver' => 'kavenegar', 'kavenegar' => ['api_key' => '…', 'template' => 'template-name']]` |
| **SMS.ir** | A *verify* template with one parameter named `CODE` | `'sms' => ['driver' => 'smsir', 'smsir' => ['api_key' => '…', 'template_id' => 123456]]` |
| **Ghasedak** | A verification template whose first parameter is the code | `'sms' => ['driver' => 'ghasedak', 'ghasedak' => ['api_key' => '…', 'template' => 'template-name']]` |

The request each adapter sends is written at the top of its file in
`app/Support/Sms/`. They were built from the gateways' published API
documentation and tested against recorded responses, **not against the live
services** (they cannot be reached from where this was written). Before
opening sign-in to readers, sign in once yourself at `/account` and confirm
the code arrives; if the gateway has changed its API, the error is written to
the PHP error log (cPanel → Errors) without the API key.

This is the only outbound request the site ever makes, and only on the
sign-in page. Because the gateways are inside Iran, sign-in keeps working
when international routes are cut.

Costs are bounded by the limits in `app/config.php` under `otp`: one code per
number per minute and five a day, ten per network address per hour, and 300
for the whole site per hour. Lower `global_per_hour` if your SMS credit is
small.

Two switches in **Admin → Settings → Contributions**: whether readers can
sign in and send articles at all, and whether the worker's LLM judge may
publish an article it approves with confidence. With the second off, every
submission waits for you.

## Updating later

With Git: push, then *Update from Remote* → *Deploy HEAD Commit* in cPanel.

Without: re-upload the changed files.

Either way, if the update included a new file in `db/migrations/`, **Admin →
Settings** shows *Database update needed*: press **Apply**. (Or run
`php tools/migrate.php`.)

Uploaded themes live in `public_html/themes/` next to the built-in one; a
deploy copies over the built-in `default` theme and leaves the others alone.

After any template or stylesheet change, flush the page cache from
**Admin → Settings**, or old markup will keep being served.

## When something is wrong

| What you see | Usually means |
|---|---|
| Blank white page | PHP error with display off. Check cPanel → Errors. |
| "Database connection failed" | Credentials in `config.local.php`, or the user was not added to the database. |
| 500 on every page | `.htaccess` — confirm the host allows `mod_rewrite`. |
| Styles missing | `public/assets/` did not get copied into `public_html/`. |
| Admin says "Invalid CSRF token" | The clock is wrong, or cookies are blocked. |
| Persian shows as `????` | Database not `utf8mb4`. Recreate it with that collation. |
| `/account` says sign-in is unavailable | `security.phone_pepper` or the `sms` settings are missing, or contributions are switched off in Settings. `preflight.php` says which. |
| Codes never arrive | The gateway's template is not approved yet, the credit is used up, or its API changed. The reason is in cPanel → Errors, logged as "SMS send failed". |
| "Git Version Control" cannot clone | The deploy key is missing or not authorised, the clone URL is the https form instead of `git@github.com:…`, or GitHub is unreachable from the host right now. |
