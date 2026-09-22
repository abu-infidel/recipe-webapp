<?php
/**
 * Public site layout. Persian, RTL.
 *
 * Every asset referenced here is served from this origin. That is deliberate:
 * during an international blackout there must be nothing external left to
 * fail, which is also what the Content-Security-Policy enforces.
 *
 * @var string      $content
 * @var string      $pageTitle
 * @var string|null $description
 * @var string|null $canonical
 * @var string|null $bodyClass
 * @var string|null $accent
 * @var bool|null   $noIndex
 * @var array|null  $jsonLd
 */

use App\Core\Config;
use App\Core\Url;

$siteName = Config::string('site.name_fa');
$title = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' — ' . $siteName : $siteName;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<?php if (!empty($description)): ?>
<meta name="description" content="<?= e(mb_substr($description, 0, 160, 'UTF-8')) ?>">
<?php endif; ?>
<?php if (!empty($noIndex)): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<?php if (!empty($canonical)): ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>

<meta property="og:type" content="website">
<meta property="og:locale" content="fa_IR">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($pageTitle ?? $siteName) ?>">
<?php if (!empty($description)): ?>
<meta property="og:description" content="<?= e(mb_substr($description, 0, 200, 'UTF-8')) ?>">
<?php endif; ?>

<link rel="icon" href="<?= e(asset('/assets/img/icon.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#b4541f">

<?php /* Preloaded because Persian text is unreadable in a fallback font. */ ?>
<link rel="preload" href="<?= e(asset('/assets/fonts/Vazirmatn-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme-init.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('/assets/css/site.css')) ?>">

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json"><?= ejs($jsonLd) ?></script>
<?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>"<?= !empty($accent) ? ' style="--accent: ' . e($accent) . '"' : '' ?>>

<a class="skip-link" href="#main">پرش به محتوای اصلی</a>

<header class="site-header">
  <div class="container site-header__inner">
    <a class="brand" href="/">
      <span class="brand__mark" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 20h16M6 20V9a6 6 0 0 1 12 0v11M9 4.5V2M15 4.5V2"/>
        </svg>
      </span>
      <span class="brand__text"><?= e($siteName) ?></span>
    </a>

    <span class="header-spacer"></span>

    <form class="search-box" role="search" action="/search" method="get" data-search>
      <span class="search-box__icon" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
        </svg>
      </span>
      <label class="visually-hidden" for="q">جست‌وجو در دستورها و راهنماها</label>
      <input class="search-box__input" type="search" id="q" name="q"
             placeholder="جست‌وجو…" autocomplete="off"
             role="combobox" aria-autocomplete="list" aria-expanded="false"
             aria-controls="search-suggestions"
             value="<?= e($query ?? '') ?>">
      <ul class="suggestions" id="search-suggestions" role="listbox"
          aria-label="پیشنهادها" data-suggestions></ul>
    </form>

    <button class="icon-button" type="button" data-theme-toggle aria-label="تغییر حالت نمایش" aria-pressed="false">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
      </svg>
    </button>
  </div>
</header>

<main id="main"><?= $content ?></main>

<footer class="site-footer">
  <div class="container site-footer__inner">
    <div>
      <p><?= e($siteName) ?></p>
      <p class="privacy-note">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z"/>
        </svg>
        بدون کوکی و بدون ردیابی
      </p>
    </div>
    <nav aria-label="پیوندهای پایانی">
      <a href="/">خانه</a>
      <a href="/search">جست‌وجو</a>
      <a href="/about-for-ai">درباره سایت</a>
      <a href="/llms.txt">llms.txt</a>
      <?php /* Honeypot: invisible to readers and to screen readers, so only a
               crawler following every href in the markup will hit it. */ ?>
      <a href="<?= e(\App\Core\Config::string('security.bot_gate.honeypot_path')) ?>"
         aria-hidden="true" tabindex="-1"
         style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)">.</a>
    </nav>
  </div>
</footer>

<script src="<?= e(asset('/assets/js/persian.js')) ?>" defer></script>
<script src="<?= e(asset('/assets/js/site.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
