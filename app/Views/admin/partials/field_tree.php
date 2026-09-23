<?php
/**
 * Recursive tree rows, each with an inline edit form.
 *
 * @var array  $nodes
 * @var string $csrf
 */
?>
<ul class="tree-list">
<?php foreach ($nodes as $node): ?>
  <li>
    <div class="tree-row">
      <?php if (!empty($node['icon'])): ?><span aria-hidden="true"><?= e($node['icon']) ?></span><?php endif; ?>
      <span class="tree-row__title fa"><?= e($node['title_fa']) ?></span>
      <span class="tree-row__path"><?= e($node['path']) ?></span>
      <span class="hint"><?= (int) $node['subtree_count'] ?> article(s)</span>

      <span class="tree-row__actions">
        <details>
          <summary class="btn btn--sm" style="list-style:none">Edit</summary>
          <form method="post" action="/admin/fields/<?= (int) $node['id'] ?>/update"
                style="margin-top:10px; min-width:260px; background:var(--a-surface-2); padding:12px; border-radius:6px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
              <label>Title</label>
              <input class="fa" type="text" name="title_fa" value="<?= e($node['title_fa']) ?>">
            </div>
            <div class="field">
              <label>Blurb</label>
              <input class="fa" type="text" name="blurb_fa" value="<?= e((string) ($node['blurb_fa'] ?? '')) ?>">
            </div>
            <div class="field-row">
              <div class="field">
                <label>Icon</label>
                <input type="text" name="icon" maxlength="8" value="<?= e((string) ($node['icon'] ?? '')) ?>">
              </div>
              <div class="field">
                <label>Accent</label>
                <input type="color" name="accent_color" value="<?= e((string) ($node['accent_color'] ?: '#b4541f')) ?>">
              </div>
              <div class="field">
                <label>Sort</label>
                <input type="number" name="sort_order" value="<?= (int) ($node['sort_order'] ?? 0) ?>">
              </div>
            </div>
            <div class="field checkbox">
              <input type="checkbox" name="is_published" id="pub-<?= (int) $node['id'] ?>" checked>
              <label for="pub-<?= (int) $node['id'] ?>" style="margin:0">Visible</label>
            </div>
            <button class="btn btn--primary btn--sm" type="submit">Save</button>
          </form>
        </details>

        <form method="post" action="/admin/fields/<?= (int) $node['id'] ?>/delete"
              data-confirm="Delete this field? This is refused if it still holds articles.">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn--sm btn--danger" type="submit">Delete</button>
        </form>
      </span>
    </div>

    <?php if (!empty($node['children'])): ?>
      <?= \App\Core\View::render('admin.partials.field_tree', ['nodes' => $node['children'], 'csrf' => $csrf]) ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
