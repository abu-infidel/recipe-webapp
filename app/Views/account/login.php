<?php
/**
 * Sign in or sign up: one form, a phone number. account.js solves the
 * proof of work (data-pow-*) before the form is sent, which is what makes
 * requesting codes expensive for a script.
 *
 * @var bool        $available
 * @var string      $nonce
 * @var int         $difficulty
 * @var string|null $error
 * @var string      $phone
 */
?>
<div class="account account--narrow">
  <h1>ورود یا ثبت‌نام</h1>
  <p class="account__lead">برای فرستادن دستور پخت یا راهنما، با شماره همراه وارد شوید. کدی یک‌بارمصرف برایتان پیامک می‌شود.</p>

  <?php if ($error !== null): ?>
    <p class="account__error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <?php if (!$available): ?>
    <p class="account__note">ورود با پیامک فعلاً در دسترس نیست. لطفاً بعداً سر بزنید.</p>
  <?php else: ?>
    <form class="account__form" method="post" action="/account/code" data-pow-form
          data-pow-nonce="<?= e($nonce) ?>" data-pow-difficulty="<?= (int) $difficulty ?>">
      <input type="hidden" name="nonce" value="<?= e($nonce) ?>">
      <input type="hidden" name="solution" value="" data-pow-solution>
      <label for="phone">شماره همراه</label>
      <input type="tel" id="phone" name="phone" inputmode="tel" autocomplete="tel" dir="ltr"
             placeholder="۰۹۱۲۳۴۵۶۷۸۹" required maxlength="20" value="<?= e($phone) ?>">
      <button type="submit" data-pow-submit>دریافت کد</button>
      <p class="account__status" data-pow-status aria-live="polite"></p>
      <noscript><p class="account__note">برای دریافت کد، جاوااسکریپت مرورگر باید روشن باشد.</p></noscript>
    </form>

    <div class="account__privacy">
      <h2>درباره شماره شما</h2>
      <p>شماره همراه فقط برای فرستادن همین کد به کار می‌رود و هیچ‌جا ذخیره نمی‌شود؛ آنچه نگه می‌داریم یک اثر رمزنگاری‌شده از آن است که نمی‌توان شماره را از آن بازسازی کرد. خوانندگان سایت هیچ کوکی‌ای دریافت نمی‌کنند؛ پس از ورود، تنها یک کوکی برای همین بخش حساب کاربری گذاشته می‌شود.</p>
    </div>
  <?php endif; ?>
</div>
