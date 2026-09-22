<?php
/**
 * The field tree as plain nested lists.
 *
 * Used for the no-JavaScript fallback on the homepage and for the AI-facing
 * about page. Recursion is fine here: the tree is at most eight levels deep
 * and is rendered at most once per response.
 *
 * @var array $nodes
 */

use App\Core\Url;
?>
<ul>
<?php foreach ($nodes as $node): ?>
  <li>
    <a href="<?= e(Url::field((string) $node['path'])) ?>"><?= e($node['title_fa']) ?></a>
    <?php if ((int) $node['subtree_count'] > 0): ?>
      <small>(<?= e(fa((int) $node['subtree_count'])) ?>)</small>
    <?php endif; ?>
    <?php if (!empty($node['children'])): ?>
      <?= \App\Core\View::render('partials.tree_list', ['nodes' => $node['children']]) ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
