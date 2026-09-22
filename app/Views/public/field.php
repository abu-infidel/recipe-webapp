<?php
/**
 * A field page: sub-fields as tiles, then the articles in this branch.
 *
 * @var array $field
 * @var array $children
 * @var array $articles
 * @var array $ancestors
 */

use App\Core\Url;
?>
<div class="container">

  <header class="page-header">
    <nav class="breadcrumbs" aria-label="مسیر">
      <a href="/">خانه</a>
      <?php foreach ($ancestors as $ancestor): ?>
        <span aria-hidden="true">/</span>
        <a href="<?= e(Url::field((string) $ancestor['path'])) ?>"><?= e($ancestor['title_fa']) ?></a>
      <?php endforeach; ?>
    </nav>

    <h1 class="page-header__title"><?= e($field['title_fa']) ?></h1>
    <?php if (!empty($field['blurb_fa'])): ?>
      <p class="page-header__blurb"><?= e($field['blurb_fa']) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($children !== []): ?>
    <section aria-label="زیربخش‌ها">
      <div class="menu__level" data-density="<?= count($children) <= 6 ? 'spacious' : (count($children) <= 14 ? 'medium' : 'compact') ?>">
        <?php foreach ($children as $i => $child): ?>
          <a class="tile" href="<?= e(Url::field((string) $child['path'])) ?>"
             style="--i: <?= (int) $i ?><?= !empty($child['accent_color']) ? '; --tile-accent: ' . e($child['accent_color']) : '' ?>">
            <?php if (!empty($child['icon'])): ?>
              <span class="tile__icon" aria-hidden="true"><?= e($child['icon']) ?></span>
            <?php endif; ?>
            <span class="tile__title"><?= e($child['title_fa']) ?></span>
            <?php if (!empty($child['blurb_fa'])): ?>
              <span class="tile__blurb"><?= e($child['blurb_fa']) ?></span>
            <?php endif; ?>
            <span class="tile__meta">
              <span><?= e(fa((int) $child['subtree_count'])) ?> نوشته</span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="article-section" aria-label="نوشته‌ها">
    <h2 class="article-section__title">
      <?= $children === [] ? 'نوشته‌های این بخش' : 'تازه‌ترین نوشته‌ها' ?>
    </h2>

    <?php if ($articles === []): ?>
      <div class="empty-state">
        <p class="empty-state__title">هنوز نوشته‌ای اینجا نیست</p>
        <p>این بخش به‌زودی تکمیل می‌شود.</p>
      </div>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($articles as $article): ?>
          <?php $path = $article['field_path'] ?? $field['path']; ?>
          <a class="card" href="<?= e(Url::article((string) $path, (string) $article['slug'])) ?>">
            <span class="card__title"><?= e($article['title_fa']) ?></span>
            <?php if (!empty($article['summary_fa'])): ?>
              <span class="card__excerpt"><?= e(mb_substr(strip_tags((string) $article['summary_fa']), 0, 140, 'UTF-8')) ?></span>
            <?php endif; ?>
            <span class="card__meta">
              <span><?= e(fa((int) $article['reading_minutes'])) ?> دقیقه مطالعه</span>
              <?php if ($article['kind'] === 'recipe'): ?><span>دستور پخت</span><?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?= \App\Core\View::render('partials.ad_slot', ['slot' => 'field-footer', 'fieldId' => (int) $field['id']]) ?>
</div>
