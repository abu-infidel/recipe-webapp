<?php
/**
 * @var array  $tree
 * @var array  $flat
 * @var string $csrf
 */
?>
<div class="admin-head">
  <h1>Fields</h1>
</div>

<div class="card">
  <p class="card__title">Add a field</p>
  <form method="post" action="/admin/fields/create">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field-row">
      <div class="field">
        <label for="title_fa">Title (Persian)</label>
        <input class="fa" type="text" id="title_fa" name="title_fa" required>
      </div>
      <div class="field">
        <label for="parent_id">Parent</label>
        <select id="parent_id" name="parent_id">
          <option value="0">— top level —</option>
          <?php foreach ($flat as $field): ?>
            <option value="<?= (int) $field['id'] ?>">
              <?= e(str_repeat('— ', (int) $field['depth']) . $field['title_fa']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="icon">Icon</label>
        <input type="text" id="icon" name="icon" maxlength="8" placeholder="🍲">
        <p class="hint">A single emoji.</p>
      </div>
      <div class="field">
        <label for="accent_color">Accent</label>
        <input type="color" id="accent_color" name="accent_color" value="#b4541f">
      </div>
      <div class="field">
        <label for="sort_order">Sort</label>
        <input type="number" id="sort_order" name="sort_order" value="0">
      </div>
    </div>

    <div class="field">
      <label for="blurb_fa">Blurb (Persian)</label>
      <input class="fa" type="text" id="blurb_fa" name="blurb_fa" maxlength="400">
      <p class="hint">Shown on the tile in the homepage menu.</p>
    </div>

    <div class="field checkbox">
      <input type="checkbox" id="is_published" name="is_published" checked>
      <label for="is_published" style="margin:0">Visible on the site</label>
    </div>

    <button class="btn btn--primary" type="submit">Create field</button>
    <p class="hint" style="margin-top:8px">
      Unpublishing a field hides everything beneath it, including its articles.
    </p>
  </form>
</div>

<div class="card">
  <p class="card__title">The tree</p>
  <?= \App\Core\View::render('admin.partials.field_tree', ['nodes' => $tree, 'csrf' => $csrf]) ?>
</div>
