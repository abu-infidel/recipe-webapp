<?php
/**
 * Search results.
 *
 * @var string $query
 * @var array  $results
 * @var int    $page
 * @var bool   $hasMore
 */

use App\Core\Url;
use App\Domain\SearchIndex;
?>
<div class="container">
  <header class="page-header">
    <h1 class="page-header__title">
      <?= $query === '' ? 'جست‌وجو' : 'نتیجه‌های «' . e($query) . '»' ?>
    </h1>
    <?php if ($query !== ''): ?>
      <p class="page-header__blurb">
        <?= $results === [] ? 'چیزی پیدا نشد.' : e(fa(count($results))) . ' نتیجه در این صفحه' ?>
      </p>
    <?php endif; ?>
  </header>

  <?php if ($query === ''): ?>
    <div class="empty-state">
      <p class="empty-state__title">چه چیزی می‌خواهید پیدا کنید؟</p>
      <p>نام یک غذا، یک ماده اولیه یا یک موضوع را بنویسید.</p>
    </div>

  <?php elseif ($results === []): ?>
    <div class="empty-state">
      <p class="empty-state__title">نتیجه‌ای پیدا نشد</p>
      <p>املای واژه را بررسی کنید یا واژه‌ای کلی‌تر را امتحان کنید.</p>
    </div>

  <?php else: ?>
    <ol class="result-list">
      <?php foreach ($results as $result): ?>
        <li class="result">
          <h2 class="result__title">
            <a href="<?= e(Url::article((string) $result['field_path'], (string) $result['slug'])) ?>">
              <?= e($result['title_fa']) ?>
            </a>
          </h2>
          <p class="result__path"><?= e(str_replace('/', ' ← ', (string) $result['field_path'])) ?></p>
          <?php if (!empty($result['summary_fa'])): ?>
            <p class="result__snippet"><?= SearchIndex::snippet((string) $result['summary_fa'], $query) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>

    <nav class="pagination" aria-label="صفحه‌بندی">
      <?php if ($page > 1): ?>
        <a href="<?= e(Url::search($query)) ?>&amp;page=<?= $page - 1 ?>">صفحه پیش</a>
      <?php endif; ?>
      <?php if ($hasMore): ?>
        <a href="<?= e(Url::search($query)) ?>&amp;page=<?= $page + 1 ?>">صفحه بعد</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</div>
