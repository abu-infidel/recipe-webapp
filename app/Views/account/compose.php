<?php
/**
 * The contributor's editor. The body, recipe and references are built by
 * /assets/core/composer.js from #composer-data and posted as JSON in `doc`.
 *
 * @var array|null  $submission
 * @var array       $meta
 * @var list<array> $fields
 * @var list<string> $errors
 * @var array       $editorData
 * @var string      $csrf
 */

$action = $submission === null ? '/account/new' : '/account/submissions/' . (int) $submission['id'];
$heroId = (int) ($meta['hero_media_id'] ?? 0);
$hero = $editorData['media'][$heroId] ?? null;
?>
<div class="account account--wide">
  <p><a href="/account">بازگشت به حساب</a></p>
  <h1><?= $submission === null ? 'نوشته تازه' : 'ویرایش نوشته' ?></h1>

  <?php if ($submission !== null && $submission['status'] === 'needs_changes' && !empty($submission['reviewer_note'])): ?>
    <div class="account__review-note">
      <strong>یادداشت بررسی‌کننده:</strong>
      <p><?= e($submission['reviewer_note']) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="account__error" role="alert">
      <p>نوشته فرستاده نشد:</p>
      <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <details class="account__guide">
    <summary>راهنمای نوشتن</summary>
    <ul>
      <li>هر ادعای مشخص — دما، زمان، مقدار، نکته ایمنی — باید به یک منبع ارجاع داشته باشد: شماره منبع را در کروشه بنویسید، مثلاً [۱].</li>
      <li>برای عددها، جمله‌ای از منبع را که همان عدد را می‌گوید در «نقل‌قول» بیاورید؛ این کار بررسی را سریع‌تر می‌کند.</li>
      <li>برای پررنگ کردن یک عبارت آن را میان دو ستاره بگذارید: **مهم**. پیوند به نوشته‌های دیگر سایت خودکار افزوده می‌شود.</li>
      <li>متن را از جای دیگر کپی نکنید؛ با زبان خودتان بنویسید.</li>
    </ul>
  </details>

  <form class="account__form composer-form" method="post" action="<?= e($action) ?>" data-composer-form>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="doc" value="" data-composer-doc>

    <label for="title">عنوان</label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= e($meta['title']) ?>">

    <div class="account__row">
      <div>
        <label for="field_id">بخش</label>
        <select id="field_id" name="field_id" required>
          <option value="">انتخاب کنید…</option>
          <?php foreach ($fields as $field): ?>
            <option value="<?= $field['id'] ?>" <?= (int) $meta['field_id'] === $field['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $field['depth']) ?><?= e($field['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="kind">نوع</label>
        <select id="kind" name="kind" data-composer-kind>
          <option value="recipe" <?= $meta['kind'] === 'recipe' ? 'selected' : '' ?>>دستور پخت</option>
          <option value="guide" <?= $meta['kind'] === 'guide' ? 'selected' : '' ?>>راهنما</option>
        </select>
      </div>
    </div>

    <label for="summary">خلاصه <small>— یکی دو جمله که در فهرست‌ها و نتیجه جست‌وجو دیده می‌شود</small></label>
    <textarea id="summary" name="summary" rows="2" maxlength="600"><?= e($meta['summary']) ?></textarea>

    <fieldset class="composer-hero" data-composer-hero>
      <legend>تصویر اصلی (اختیاری)</legend>
      <input type="hidden" name="hero_media_id" value="<?= $heroId ?: '' ?>" data-hero-id>
      <img alt="" data-hero-preview <?= $hero === null ? 'hidden' : 'src="' . e($hero['url']) . '"' ?>>
      <label for="hero-alt">توضیح تصویر برای کسانی که آن را نمی‌بینند</label>
      <input type="text" id="hero-alt" data-hero-alt>
      <input type="checkbox" data-hero-ai hidden>
      <input type="file" accept="image/jpeg,image/png,image/webp" data-hero-file aria-label="بارگذاری تصویر اصلی">
      <button type="button" data-hero-remove <?= $hero === null ? 'hidden' : '' ?>>حذف تصویر</button>
      <p class="account__status" data-hero-status></p>
    </fieldset>

    <div data-composer></div>

    <div class="composer-actions">
      <button type="submit"><?= $submission === null ? 'فرستادن برای بررسی' : 'فرستادن دوباره' ?></button>
    </div>
  </form>

  <?php if ($submission !== null): ?>
    <form method="post" action="/account/submissions/<?= (int) $submission['id'] ?>/withdraw" class="account__withdraw" data-confirm="این نوشته پس گرفته شود؟">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="account__link-button">پس گرفتن نوشته</button>
    </form>
  <?php endif; ?>
</div>

<script type="application/json" id="composer-data"><?= ejs($editorData) ?></script>
