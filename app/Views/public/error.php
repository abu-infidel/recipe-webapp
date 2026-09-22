<?php
/**
 * @var int    $code
 * @var string $headline
 * @var string $message
 */
?>
<div class="container">
  <div class="empty-state">
    <p style="font-size: var(--step-4); font-weight: 700; color: var(--text-faint);"><?= e(fa($code)) ?></p>
    <p class="empty-state__title"><?= e($headline) ?></p>
    <p><?= e($message) ?></p>
    <p style="margin-block-start: var(--space-5);">
      <a href="/">بازگشت به صفحه اصلی</a>
    </p>
  </div>
</div>
