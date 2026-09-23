<?php
/**
 * The article editor. The body, recipe and references are built by
 * /assets/core/composer.js from the #composer-data island and posted back as
 * JSON in the `doc` field; the rest are ordinary inputs.
 *
 * @var array|null $article     null when creating
 * @var array      $meta        field_id, kind, title, summary, slug, hero_media_id
 * @var list<array> $fields     id, title, depth
 * @var list<array> $findings   citation findings from the last save
 * @var list<string> $errors    why the last save was refused
 * @var string|null $publicUrl
 * @var array      $editorData
 * @var string     $csrf
 */

$published = $article !== null && $article['status'] === 'published';
$action = $article === null ? '/admin/articles/new' : '/admin/articles/' . (int) $article['id'] . '/edit';
$heroId = (int) ($meta['hero_media_id'] ?? 0);
$hero = $editorData['media'][$heroId] ?? null;
?>
<div class="admin-head">
  <div>
    <h1><?= $article === null ? 'New article' : 'Edit article' ?></h1>
    <?php if ($article !== null): ?>
      <p class="hint" style="margin-top:6px">
        <span class="badge badge--<?= e($article['status']) ?>"><?= e($article['status']) ?></span>
        version <?= (int) $article['version'] ?>
      </p>
    <?php endif; ?>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap">
    <?php if ($article !== null): ?>
      <a class="btn" href="/admin/articles/<?= (int) $article['id'] ?>">Review &amp; publish →</a>
    <?php endif; ?>
    <?php if ($publicUrl !== null): ?>
      <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">View live ↗</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors !== []): ?>
  <div class="card" role="alert">
    <p class="card__title">Not saved</p>
    <div class="findings">
      <?php foreach ($errors as $error): ?>
        <div class="finding finding--error"><span class="finding__code">error</span><span><?= e($error) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php elseif ($findings !== []): ?>
  <div class="card">
    <p class="card__title">Citation check</p>
    <div class="findings">
      <?php foreach ($findings as $finding): ?>
        <div class="finding finding--<?= e($finding['severity'] ?? 'warn') ?>">
          <span class="finding__code"><?= e($finding['code'] ?? '') ?></span>
          <span><?= e($finding['message'] ?? '') ?><?php if (!empty($finding['anchor'])): ?> <span class="hint">(<?= e($finding['anchor']) ?>)</span><?php endif; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hint" style="margin-top:8px">Anchors read s<em>section</em>p<em>block</em>; s1 is the introduction.</p>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="composer-form" data-composer-form>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="doc" value="" data-composer-doc>

  <div class="card">
    <div class="field">
      <label for="title">Title</label>
      <input type="text" id="title" name="title" class="fa" dir="rtl" required maxlength="200" value="<?= e($meta['title']) ?>">
    </div>
    <div class="field-row">
      <div class="field">
        <label for="field_id">Section</label>
        <select id="field_id" name="field_id" required <?= $published ? 'disabled' : '' ?>>
          <option value="">Choose…</option>
          <?php foreach ($fields as $field): ?>
            <option value="<?= $field['id'] ?>" <?= (int) $meta['field_id'] === $field['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $field['depth']) ?><?= e($field['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($published): ?>
          <input type="hidden" name="field_id" value="<?= (int) $meta['field_id'] ?>">
          <p class="hint">Fixed while published: moving it would break links to it.</p>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="kind">Kind</label>
        <select id="kind" name="kind" data-composer-kind>
          <?php foreach (['recipe' => 'Recipe', 'guide' => 'Guide', 'topic' => 'Topic'] as $value => $label): ?>
            <option value="<?= $value ?>" <?= $meta['kind'] === $value ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="slug">Address</label>
        <input type="text" id="slug" name="slug" class="fa" dir="rtl" value="<?= e($meta['slug']) ?>" <?= $published ? 'readonly' : '' ?> placeholder="made from the title">
      </div>
    </div>
    <div class="field">
      <label for="summary">Summary <span class="hint">— one or two sentences; shown in lists and search results</span></label>
      <textarea id="summary" name="summary" class="fa composer-text" dir="rtl" rows="2" maxlength="600"><?= e($meta['summary']) ?></textarea>
    </div>

    <div class="field">
      <label>Lead image</label>
      <div class="composer-hero" data-composer-hero>
        <input type="hidden" name="hero_media_id" value="<?= $heroId ?: '' ?>" data-hero-id>
        <img alt="" data-hero-preview <?= $hero === null ? 'hidden' : 'src="' . e($hero['url']) . '"' ?>>
        <div class="field-row">
          <input type="text" class="fa" dir="rtl" placeholder="Describe the image (alt text)" data-hero-alt>
          <span class="checkbox"><input type="checkbox" id="hero-ai" data-hero-ai><label for="hero-ai" style="margin:0">AI-generated</label></span>
        </div>
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-hero-file>
        <button class="btn btn--sm" type="button" data-hero-remove <?= $hero === null ? 'hidden' : '' ?>>Remove</button>
        <p class="hint" data-hero-status></p>
      </div>
    </div>
  </div>

  <div data-composer></div>

  <div class="composer-actions">
    <button class="btn btn--primary" type="submit"><?= $published ? 'Save and republish' : 'Save draft' ?></button>
    <span class="hint">Formatting: <code>**bold**</code> · cite a reference with <code>[1]</code> or <code>[1، 2]</code> · internal links are added automatically at publish.</span>
  </div>
</form>

<script type="application/json" id="composer-data"><?= ejs($editorData) ?></script>
