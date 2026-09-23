<?php
/**
 * Admin layout. English, LTR — the mirror image of the public site, which is
 * Persian and RTL.
 *
 * @var string $content
 * @var string $pageTitle
 * @var array  $user
 * @var string $nav
 */

use App\Core\Request;

$flash = $_GET['m'] ?? null;
$flashType = ($_GET['t'] ?? 'ok') === 'error' ? 'error' : 'ok';

$links = [
    'dashboard' => ['/admin', 'Dashboard'],
    'articles'  => ['/admin/articles', 'Articles'],
    'submissions' => ['/admin/submissions', 'Submissions' . (($pending = \App\Domain\Submissions::pendingCount()) > 0 ? " ({$pending})" : '')],
    'fields'    => ['/admin/fields', 'Fields'],
    'themes'    => ['/admin/themes', 'Themes'],
    'settings'  => ['/admin/settings', 'Settings'],
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> — Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e(asset('/assets/img/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/admin/admin.css')) ?>">
<?php /* Persian drafts inside the admin still need the Persian face. */ ?>
<style>
  @font-face {
    font-family: 'Vazirmatn';
    src: url('<?= e(asset('/assets/fonts/Vazirmatn-Regular.woff2')) ?>') format('woff2');
    font-weight: 400; font-display: swap;
  }
</style>
</head>
<body class="admin">
<div class="admin-shell">

  <nav class="admin-side" aria-label="Admin sections">
    <div class="admin-side__brand">
      <span class="admin-side__mark" aria-hidden="true">R</span>
      <span>Admin</span>
    </div>

    <?php foreach ($links as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= ($nav ?? '') === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>

    <div class="admin-side__foot">
      <div><?= e($user['name'] ?? '') ?></div>
      <div style="margin-bottom:8px"><?= e($user['email'] ?? '') ?></div>
      <form method="post" action="/admin/logout">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <button class="btn btn--sm" type="submit">Sign out</button>
      </form>
      <p style="margin-top:12px">
        <a href="/" target="_blank" rel="noopener">View site ↗</a>
      </p>
    </div>
  </nav>

  <main class="admin-main">
    <?php if ($flash !== null): ?>
      <div class="flash flash--<?= e($flashType) ?>"><?= e((string) $flash) ?></div>
    <?php endif; ?>

    <?= $content ?>
  </main>
</div>

<script src="<?= e(asset('/assets/admin/common.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
