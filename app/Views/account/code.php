<?php
/**
 * @var string      $token   binds the code to this browser's request
 * @var string      $masked  0912•••4567, or '' after a wrong guess
 * @var int         $ttl     seconds until the code expires
 * @var string|null $error
 */
?>
<div class="account account--narrow">
  <h1>کد تأیید</h1>
  <?php if ($masked !== ''): ?>
    <p class="account__lead">کد شش‌رقمی به شماره <bdi dir="ltr"><?= e($masked) ?></bdi> پیامک شد. این کد <?= e(fa(intdiv($ttl, 60))) ?> دقیقه اعتبار دارد.</p>
  <?php endif; ?>

  <?php if ($error !== null): ?>
    <p class="account__error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form class="account__form" method="post" action="/account/verify">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label for="code">کد</label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr"
           pattern="[0-9۰-۹]{6}" maxlength="6" required autofocus class="account__code">
    <button type="submit">ورود</button>
  </form>

  <p class="account__note"><a href="/account">کد نرسید؟ دوباره تلاش کنید</a></p>
</div>
