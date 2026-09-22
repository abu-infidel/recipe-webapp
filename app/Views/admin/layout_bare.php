<?php
/**
 * Layout for pages shown before sign-in. No navigation, nothing that assumes
 * a session.
 *
 * @var string $content
 * @var string $pageTitle
 */
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e(asset('/assets/img/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
</head>
<body class="admin">
<div class="login-shell"><?= $content ?></div>
</body>
</html>
