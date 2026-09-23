<?php
/**
 * @var array  $articles
 * @var string $status
 * @var string $csrf
 */

use App\Core\Database;

$fields = Database::all('SELECT id, path, title_fa, depth FROM fields ORDER BY path');
$filters = ['all' => 'All', 'draft' => 'Draft', 'in_review' => 'In review', 'published' => 'Published', 'archived' => 'Archived'];
?>
<div class="admin-head">
  <h1>Articles</h1>
  <div style="display:flex; gap:6px; flex-wrap:wrap">
    <a class="btn btn--sm btn--primary" href="/admin/articles/new?kind=recipe">New recipe</a>
    <a class="btn btn--sm btn--primary" href="/admin/articles/new?kind=guide">New guide</a>
    <?php foreach ($filters as $key => $label): ?>
      <a class="btn btn--sm" href="/admin/articles?status=<?= e($key) ?>"
         <?= $status === $key ? 'style="border-color:var(--a-accent); color:var(--a-accent)"' : '' ?>>
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <p class="card__title">Commission a topic</p>
  <form method="post" action="/admin/articles/commission">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field-row">
      <div class="field">
        <label for="topic">Topic</label>
        <input class="fa" type="text" id="topic" name="topic" placeholder="قورمه سبزی" required>
      </div>
      <div class="field">
        <label for="field_id">Field</label>
        <select id="field_id" name="field_id" required>
          <?php foreach ($fields as $field): ?>
            <option value="<?= (int) $field['id'] ?>">
              <?= e(str_repeat('— ', (int) $field['depth']) . $field['title_fa']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="kind">Kind</label>
        <select id="kind" name="kind">
          <option value="recipe">Recipe</option>
          <option value="guide" selected>Guide</option>
          <option value="topic">Topic</option>
        </select>
      </div>
      <div class="field" style="display:flex; align-items:flex-end">
        <button class="btn btn--primary" type="submit">Queue for research</button>
      </div>
    </div>
    <p class="hint">
      The worker picks this up, researches it, and lands a draft here.
      Nothing goes public until you publish it.
    </p>
  </form>
</div>

<div class="card">
  <?php if ($articles === []): ?>
    <div class="empty">No articles yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Title</th><th>Field</th><th>Kind</th><th>Status</th><th>Sources</th><th>Flags</th><th>Updated</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($articles as $article): ?>
          <?php $flags = json_decode((string) ($article['quality_flags'] ?? '[]'), true) ?: []; ?>
          <tr>
            <td class="fa" style="max-width:26ch"><?= e($article['title_fa']) ?></td>
            <td class="fa"><?= e($article['field_title']) ?></td>
            <td><?= e($article['kind']) ?></td>
            <td><span class="badge badge--<?= e($article['status']) ?>"><?= e($article['status']) ?></span></td>
            <td><?= (int) $article['source_count'] ?></td>
            <td>
              <?php if ($flags === []): ?>
                <span class="badge badge--published">clean</span>
              <?php else: ?>
                <span class="badge badge--in_review"><?= count($flags) ?></span>
              <?php endif; ?>
            </td>
            <td class="hint"><?= e(date('Y-m-d', (int) strtotime((string) $article['updated_at']))) ?></td>
            <td style="text-align:right">
              <a class="btn btn--sm" href="/admin/articles/<?= (int) $article['id'] ?>">Review</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
