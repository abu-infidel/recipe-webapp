<?php
/**
 * Shown when the rate limiter trips. A reader waits under a second; a
 * scraper pays this on every request.
 *
 * @var string $nonce
 * @var int    $difficulty
 * @var int    $retryAfter
 */
?>
<div class="container">
  <div class="empty-state" data-challenge
       data-nonce="<?= e($nonce) ?>" data-difficulty="<?= (int) $difficulty ?>">
    <p class="empty-state__title">یک لحظه صبر کنید…</p>
    <p data-challenge-status>در حال بررسی مرورگر شما. این کار چند لحظه طول می‌کشد.</p>

    <noscript>
      <p style="margin-block-start: var(--space-5);">
        درخواست‌های زیادی از این نشانی دریافت شده است.
        لطفاً <?= e(fa(max(1, (int) ceil($retryAfter / 60)))) ?> دقیقه دیگر دوباره تلاش کنید.
      </p>
    </noscript>
  </div>
</div>
