<?php
/**
 * A submission that can no longer be edited.
 *
 * @var array       $submission
 * @var string      $preview     composer HTML, built from escaped text
 * @var string|null $articleUrl
 */

$labels = ['approved' => 'پذیرفته شد', 'rejected' => 'پذیرفته نشد', 'withdrawn' => 'پس گرفته شد'];
?>
<div class="account">
  <p><a href="/account">بازگشت به حساب</a></p>
  <h1><?= e($submission['title_fa']) ?></h1>
  <p><span class="account__badge account__badge--<?= e($submission['status']) ?>"><?= e($labels[$submission['status']] ?? $submission['status']) ?></span></p>
  <?php if (!empty($submission['reviewer_note'])): ?>
    <p class="account__review-note"><?= e($submission['reviewer_note']) ?></p>
  <?php endif; ?>
  <?php if ($articleUrl !== null): ?>
    <p><a class="account__button" href="<?= e($articleUrl) ?>">دیدن در سایت</a></p>
  <?php endif; ?>
  <div class="prose account__preview"><?= $preview ?></div>
</div>
