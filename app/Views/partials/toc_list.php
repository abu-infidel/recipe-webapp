<?php
/**
 * Nested contents list. Recursive, bounded by heading depth (h2–h4).
 *
 * @var array $items
 */
if ($items === []) {
    return;
}
?>
<ul>
<?php foreach ($items as $item): ?>
  <li>
    <a href="#<?= e(rawurlencode($item['id'])) ?>"><?= e($item['text']) ?></a>
    <?php if (!empty($item['children'])): ?>
      <?= \App\Core\View::render('partials.toc_list', ['items' => $item['children']]) ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
