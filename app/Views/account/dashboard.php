<?php
/**
 * @var array       $contributor
 * @var list<array> $submissions
 * @var string|null $flash
 * @var string      $csrf
 */

$labels = [
    'pending'       => 'در انتظار بررسی',
    'needs_changes' => 'نیاز به ویرایش',
    'approved'      => 'پذیرفته شد',
    'rejected'      => 'پذیرفته نشد',
    'withdrawn'     => 'پس گرفته شد',
];
?>
<div class="account">
  <div class="account__head">
    <h1>حساب من</h1>
    <form method="post" action="/account/logout">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="account__link-button">خروج</button>
    </form>
  </div>

  <?php if ($flash !== null): ?>
    <p class="account__ok" role="status"><?= e($flash) ?></p>
  <?php endif; ?>

  <p class="account__actions">
    <a class="account__button" href="/account/new?kind=recipe">فرستادن دستور پخت</a>
    <a class="account__button account__button--quiet" href="/account/new?kind=guide">فرستادن راهنما</a>
  </p>

  <h2>نوشته‌های من</h2>
  <?php if ($submissions === []): ?>
    <p class="account__note">هنوز نوشته‌ای نفرستاده‌اید. هر نوشته پیش از انتشار بررسی می‌شود؛ منابعی که در متن به آن‌ها ارجاع می‌دهید بخش مهمی از این بررسی است.</p>
  <?php else: ?>
    <ul class="account__list">
      <?php foreach ($submissions as $item): ?>
        <li>
          <a href="/account/submissions/<?= (int) $item['id'] ?>"><?= e($item['title_fa']) ?></a>
          <span class="account__badge account__badge--<?= e($item['status']) ?>"><?= e($labels[$item['status']] ?? $item['status']) ?></span>
          <?php if (!empty($item['reviewer_note']) && in_array($item['status'], ['needs_changes', 'rejected'], true)): ?>
            <p class="account__review-note"><?= e($item['reviewer_note']) ?></p>
          <?php endif; ?>
          <?php if ($item['status'] === 'approved' && $item['article_status'] === 'published' && !empty($item['article_slug'])): ?>
            <a class="account__live" href="<?= e(\App\Core\Url::href(\App\Core\Url::article((string) $item['article_field_path'], (string) $item['article_slug']))) ?>">دیدن در سایت</a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>نام نمایشی</h2>
  <form class="account__form account__form--inline" method="post" action="/account/profile">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="display_name">نامی که زیر نوشته‌های شما نمایش داده می‌شود (اختیاری)</label>
    <input type="text" id="display_name" name="display_name" maxlength="80" value="<?= e($contributor['display_name']) ?>">
    <button type="submit">ذخیره</button>
  </form>
</div>
