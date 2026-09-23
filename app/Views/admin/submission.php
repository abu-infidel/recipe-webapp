<?php
/**
 * One contributed article, for a decision.
 *
 * @var array       $submission
 * @var array       $doc
 * @var array|null  $field
 * @var string      $preview   composer HTML: built from escaped text only
 * @var array|null  $hero
 * @var list<array> $findings
 * @var array|null  $judge     summary, issues, food_safety_ok
 * @var array|null  $recipe
 * @var string      $csrf
 */

$open = in_array($submission['status'], ['pending', 'needs_changes'], true);
$id = (int) $submission['id'];
?>
<div class="admin-head">
  <div>
    <h1 class="fa" style="max-width:48ch"><?= e($submission['title_fa']) ?></h1>
    <p class="hint" style="margin-top:6px">
      <span class="badge badge--<?= e($submission['status'] === 'approved' ? 'published' : ($submission['status'] === 'pending' ? 'in_review' : 'draft')) ?>"><?= e($submission['status']) ?></span>
      <?= e($submission['kind']) ?> · <bdi><?= e($field['title_fa'] ?? '') ?></bdi> · revision <?= (int) $submission['revision'] ?> · sent <?= e($submission['created_at']) ?>
    </p>
  </div>
  <?php if (!empty($submission['article_id'])): ?>
    <a class="btn" href="/admin/articles/<?= (int) $submission['article_id'] ?>">Open the article →</a>
  <?php endif; ?>
</div>

<div class="grid grid--2">
  <div class="card">
    <p class="card__title">Contributor</p>
    <p><bdi class="fa"><?= e($submission['display_name'] !== '' ? $submission['display_name'] : '(no display name)') ?></bdi></p>
    <p class="hint">
      <?= (int) $submission['approved_count'] ?> approved · <?= (int) $submission['rejected_count'] ?> rejected ·
      since <?= e(substr((string) $submission['contributor_since'], 0, 10)) ?> ·
      <?= e($submission['contributor_status']) ?>
    </p>
    <form method="post" action="/admin/contributors/<?= (int) $submission['contributor_id'] ?>/suspend" style="margin-top:8px"
          data-confirm="<?= $submission['contributor_status'] === 'active' ? 'Suspend this contributor? They are signed out and their open submissions are closed.' : 'Reinstate this contributor?' ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="back" value="<?= $id ?>">
      <input type="hidden" name="suspend" value="<?= $submission['contributor_status'] === 'active' ? '1' : '0' ?>">
      <button class="btn btn--sm <?= $submission['contributor_status'] === 'active' ? 'btn--danger' : '' ?>" type="submit">
        <?= $submission['contributor_status'] === 'active' ? 'Suspend contributor' : 'Reinstate contributor' ?>
      </button>
    </form>
  </div>

  <div class="card">
    <p class="card__title">Judge</p>
    <?php if ($submission['judge_verdict'] === null): ?>
      <p class="hint">Not judged yet. The worker picks this up on its next poll; you can decide without waiting.</p>
    <?php else: ?>
      <p>
        <strong><?= e($submission['judge_verdict']) ?></strong> · score <?= (int) $submission['judge_score'] ?>
        · food safety <?= !empty($judge['food_safety_ok']) ? 'ok' : '<strong>not confirmed</strong>' ?>
        <span class="hint">· <?= e((string) $submission['judge_model']) ?> · <?= e((string) $submission['judged_at']) ?></span>
      </p>
      <?php if (!empty($judge['summary'])): ?><p><?= e($judge['summary']) ?></p><?php endif; ?>
      <?php if (!empty($judge['issues'])): ?>
        <div class="findings">
          <?php foreach ($judge['issues'] as $issue): ?>
            <div class="finding finding--<?= in_array($issue['severity'] ?? '', ['blocker', 'major'], true) ? 'error' : 'warn' ?>">
              <span class="finding__code"><?= e((string) ($issue['severity'] ?? '')) ?></span>
              <span><?= e((string) ($issue['message'] ?? '')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($findings !== []): ?>
  <div class="card">
    <p class="card__title">Citation check</p>
    <div class="findings">
      <?php foreach ($findings as $finding): ?>
        <div class="finding finding--<?= e($finding['severity'] ?? 'warn') ?>">
          <span class="finding__code"><?= e($finding['code'] ?? '') ?></span>
          <span><?= e($finding['message'] ?? '') ?> <span class="hint"><?= e((string) ($finding['anchor'] ?? '')) ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($open): ?>
  <div class="card">
    <p class="card__title">Decision</p>
    <div class="grid grid--2">
      <form method="post" action="/admin/submissions/<?= $id ?>/approve">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
          <label for="approve-note">Note to the contributor (optional)</label>
          <textarea id="approve-note" name="note" class="fa composer-text" dir="rtl" rows="2"></textarea>
        </div>
        <button class="btn btn--primary" type="submit" name="publish" value="1">Approve and publish</button>
        <button class="btn" type="submit" name="publish" value="0">Approve as draft</button>
      </form>
      <form method="post" action="/admin/submissions/<?= $id ?>/decide">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
          <label for="decide-note">Note to the contributor <span class="hint">— in Persian; required to return it</span></label>
          <textarea id="decide-note" name="note" class="fa composer-text" dir="rtl" rows="2"></textarea>
        </div>
        <button class="btn" type="submit" name="status" value="needs_changes">Return for changes</button>
        <button class="btn btn--danger" type="submit" name="status" value="rejected" data-confirm="Reject this submission?">Reject</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <p class="card__title">The article</p>
  <div class="fa draft-body">
    <?php if (!empty($submission['summary_fa'])): ?><p><strong><?= e($submission['summary_fa']) ?></strong></p><?php endif; ?>
    <?php if ($hero !== null): ?><p><img src="/media/<?= e($hero['path']) ?>" alt="" style="max-width:100%; border-radius:8px"></p><?php endif; ?>
    <?php if (is_array($recipe) && $recipe !== []): ?>
      <h3>مواد لازم</h3>
      <ul><?php foreach ($recipe['ingredients'] ?? [] as $row): ?><li><?= e(trim($row['quantity'] . ' ' . $row['unit'] . ' ' . $row['name'] . ($row['note'] !== '' ? ' (' . $row['note'] . ')' : ''))) ?></li><?php endforeach; ?></ul>
      <h3>مراحل</h3>
      <ol><?php foreach ($recipe['steps'] ?? [] as $row): ?><li><?= e($row['text']) ?></li><?php endforeach; ?></ol>
    <?php endif; ?>
    <?= $preview ?>
    <?php if (!empty($doc['references'])): ?>
      <h3>منابع</h3>
      <ol>
        <?php foreach ($doc['references'] as $ref): ?>
          <li>
            <bdi dir="auto"><?= e($ref['title'] !== '' ? $ref['title'] : $ref['url']) ?></bdi> —
            <span dir="ltr"><?= e($ref['url']) ?></span>
            <?php if ($ref['quote'] !== ''): ?><blockquote class="hint" dir="auto"><?= e($ref['quote']) ?></blockquote><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>
