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

cPanel → **Git Version Control** → *Create*.

- Tick **Clone a Repository**
- Clone URL: your repository
- Repository path: `repositories/recipe-webapp`

Then edit `.cpanel.yml` in the repository and replace `USERNAME` with your
cPanel account name. Commit and push that change.

Back in cPanel: **Manage** → **Pull or Deploy** → *Update from Remote*, then
*Deploy HEAD Commit*.

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
    'security' => ['app_key' => 'PASTE 64 RANDOM HEX CHARACTERS HERE'],
    'worker'   => ['hmac_secret' => 'PASTE 64 DIFFERENT RANDOM HEX CHARACTERS'],
    'debug'    => false,
];
```

For the two random values, use cPanel's **Password Generator**, or in Terminal:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**Never commit this file.** It is already in `.gitignore`.

## 4. Set the PHP version

cPanel → **MultiPHP Manager** → select your domain → set **PHP 8.1 or newer**.

## 5. Create the tables

With Terminal:

```bash
cd ~ && php tools/migrate.php && php tools/preflight.php
```

Without Terminal: visit `https://yourdomain.ir/admin`. The first run offers to
create the schema.

`preflight.php` checks everything that commonly goes wrong — extensions,
permissions, the database clock, whether anything loads from a foreign server.
Fix anything it marks FAIL before going further.

## 6. Create your admin account

```bash
php tools/admin-user.php you@example.com "Your Name"
```

Then sign in at `https://yourdomain.ir/admin`.

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

## Updating later

With Git: push, then *Update from Remote* → *Deploy HEAD Commit* in cPanel.

Without: re-upload the changed files. Then, if the update included a new file
in `db/migrations/`, run `php tools/migrate.php` again.

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
