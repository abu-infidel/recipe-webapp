<?php
/**
 * @var string      $status
 * @var array       $statuses
 * @var list<array> $items
 */

$verdictBadge = ['approve' => 'published', 'revise' => 'in_review', 'reject' => 'error'];
?>
<div class="admin-head">
  <h1>Submissions</h1>
  <div style="display:flex; gap:6px; flex-wrap:wrap">
    <?php foreach ($statuses as $key => $label): ?>
      <a class="btn btn--sm" href="/admin/submissions?status=<?= e($key) ?>"
         <?= $status === $key ? 'style="border-color:var(--a-accent); color:var(--a-accent)"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <?php if ($items === []): ?>
    <p class="empty">Nothing here.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Title</th><th>By</th><th>Section</th><th>Judge</th><th>Sent</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <a href="/admin/submissions/<?= (int) $item['id'] ?>"><bdi class="fa"><?= e($item['title_fa']) ?></bdi></a>
              <div class="hint"><?= e($item['kind']) ?><?= (int) $item['revision'] > 1 ? ' · revision ' . (int) $item['revision'] : '' ?><?= $item['decided_by'] === 'judge' ? ' · published by the judge' : '' ?></div>
            </td>
            <td>
              <bdi><?= e($item['display_name'] !== '' ? $item['display_name'] : '(no name)') ?></bdi>
              <div class="hint"><?= (int) $item['approved_count'] ?> approved before</div>
            </td>
            <td><bdi><?= e($item['field_title']) ?></bdi></td>
            <td>
              <?php if ($item['judge_verdict'] !== null): ?>
                <span class="badge badge--<?= e($verdictBadge[$item['judge_verdict']] ?? 'draft') ?>"><?= e($item['judge_verdict']) ?> · <?= (int) $item['judge_score'] ?></span>
              <?php else: ?>
                <span class="hint">waiting</span>
              <?php endif; ?>
            </td>
            <td class="hint"><?= e($item['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
