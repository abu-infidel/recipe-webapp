<?php
/**
 * @var array $jobs
 * @var array $counts
 * @var array $drafts
 * @var array $worker
 * @var array $spend
 * @var array $recentEvents
 */
?>
<div class="admin-head">
  <h1>Dashboard</h1>
</div>

<div class="grid grid--stats">
  <div class="stat"><div class="stat__value"><?= (int) $counts['published'] ?></div><div class="stat__label">Published</div></div>
  <div class="stat"><div class="stat__value"><?= (int) $counts['draft'] + (int) $counts['review'] ?></div><div class="stat__label">Awaiting review</div></div>
  <div class="stat"><div class="stat__value"><?= (int) $jobs['queued'] ?></div><div class="stat__label">Jobs queued</div></div>
  <div class="stat"><div class="stat__value"><?= (int) $jobs['failed'] ?></div><div class="stat__label">Jobs failed</div></div>
  <div class="stat"><div class="stat__value"><?= (int) $counts['sources'] ?></div><div class="stat__label">Sources stored</div></div>
  <div class="stat">
    <div class="stat__value">$<?= number_format($spend['total'] / 1_000_000, 2) ?></div>
    <div class="stat__label">Spent (est.)</div>
  </div>
</div>

<div class="grid grid--2" style="margin-top:16px">
  <div class="card">
    <p class="card__title">Worker</p>
    <?php if ($worker['last_seen'] === null): ?>
      <p>The worker has never checked in.</p>
      <p class="hint">Start it on the VPS with <code>npm start</code>. See worker/README.md.</p>
    <?php else: ?>
      <p>
        <span class="badge badge--<?= $worker['healthy'] ? 'published' : 'error' ?>">
          <?= $worker['healthy'] ? 'Active' : 'Silent' ?>
        </span>
        Last activity <?= e((string) $worker['last_seen']) ?>
      </p>
      <?php if ($worker['stuck'] > 0): ?>
        <div class="warn-box">
          <?= (int) $worker['stuck'] ?> job(s) have an expired lease. They return to the
          queue automatically — nothing needs resetting by hand.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <p class="card__title">Queue</p>
    <table>
      <tbody>
        <?php foreach ($jobs as $status => $count): ?>
          <tr><td><?= e(ucfirst($status)) ?></td><td style="text-align:right"><?= (int) $count ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <p class="card__title">Awaiting your review</p>
  <?php if ($drafts === []): ?>
    <div class="empty">Nothing waiting. Commission a topic from the Articles page.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Title</th><th>Field</th><th>Kind</th><th>Flags</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($drafts as $draft): ?>
          <?php $flags = json_decode((string) ($draft['quality_flags'] ?? '[]'), true) ?: []; ?>
          <tr>
            <td class="fa"><?= e($draft['title_fa']) ?></td>
            <td class="fa"><?= e($draft['field_title']) ?></td>
            <td><?= e($draft['kind']) ?></td>
            <td>
              <?php if ($flags === []): ?>
                <span class="badge badge--published">clean</span>
              <?php else: ?>
                <span class="badge badge--in_review"><?= count($flags) ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <a class="btn btn--sm" href="/admin/articles/<?= (int) $draft['id'] ?>">Review</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($recentEvents !== []): ?>
<div class="card" style="margin-top:16px">
  <p class="card__title">Recent pipeline activity</p>
  <div class="events">
    <?php foreach ($recentEvents as $event): ?>
      <div class="events__row">
        <span><?= e(date('H:i:s', (int) strtotime((string) $event['created_at']))) ?></span>
        <span class="events__level--<?= e($event['level']) ?>"><?= e($event['stage']) ?></span>
        <span><?= e($event['message']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
