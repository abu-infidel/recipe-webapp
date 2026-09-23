<?php
/**
 * @var array  $adSlots
 * @var array  $blocked
 * @var array  $tokens
 * @var bool   $networkEnabled
 * @var int    $cacheFiles
 * @var array  $contributions
 * @var list<string> $pendingMigrations
 * @var string $csrf
 */
?>
<div class="admin-head">
  <h1>Settings</h1>
</div>

<?php if ($pendingMigrations !== []): ?>
  <div class="card" role="alert">
    <p class="card__title">Database update needed</p>
    <p>This version of the site needs <?= count($pendingMigrations) ?> database change(s):
      <?php foreach ($pendingMigrations as $file): ?><code><?= e($file) ?></code> <?php endforeach; ?></p>
    <p class="hint">Migrations only add tables and columns; nothing is deleted. A backup first (cPanel → Backup) is still wise.</p>
    <form method="post" action="/admin/settings/migrate" data-confirm="Apply the database changes now?">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn btn--primary" type="submit">Apply</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <p class="card__title">Contributions</p>
  <?php $c = $contributions; ?>
  <p class="hint">
    Sign-in by SMS needs a phone pepper and an SMS gateway in <code>app/config.local.php</code>.
    Pepper: <strong><?= $c['pepper'] ? 'set' : 'missing' ?></strong> ·
    gateway: <strong><?= e($c['sms_driver']) ?></strong> <?= $c['sms_ready'] ? '(configured)' : '<strong>(not configured)</strong>' ?>
  </p>
  <form method="post" action="/admin/settings/contributions" style="display:grid; gap:10px; margin-top:10px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <span class="checkbox">
      <input type="checkbox" name="enabled" id="contrib-enabled" <?= $c['enabled'] ? 'checked' : '' ?>>
      <label for="contrib-enabled" style="margin:0">Readers can sign in and send articles</label>
    </span>
    <span class="checkbox">
      <input type="checkbox" name="judge_can_publish" id="contrib-judge" <?= $c['judge_can_publish'] ? 'checked' : '' ?>>
      <label for="contrib-judge" style="margin:0">
        The LLM judge may publish an article it approves with a score of <?= (int) $c['judge_min_score'] ?> or more,
        confirmed food safety, no major issue and at least one reference
      </label>
    </span>
    <p class="hint">The judge never rejects or returns a submission; only you do. With the second box off, every submission waits for you, with the judge's notes attached.</p>
    <div><button class="btn btn--sm" type="submit">Save</button></div>
  </form>
</div>

<div class="card">
  <p class="card__title">Ad slots</p>

  <table>
    <thead><tr><th>Slot</th><th>Provider</th><th>Active</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($adSlots as $slot): ?>
        <tr>
          <td>
            <strong><?= e($slot['label']) ?></strong>
            <div class="hint"><?= e($slot['key_name']) ?></div>
          </td>
          <td colspan="3">
            <form method="post" action="/admin/settings/ad-slot/<?= (int) $slot['id'] ?>"
                  style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <select name="provider" style="width:auto">
                <option value="none"    <?= $slot['provider'] === 'none' ? 'selected' : '' ?>>None — render nothing</option>
                <option value="house"   <?= $slot['provider'] === 'house' ? 'selected' : '' ?>>House — your own banners</option>
                <option value="network" <?= $slot['provider'] === 'network' ? 'selected' : '' ?>>Network — third-party script</option>
              </select>
              <span class="checkbox">
                <input type="checkbox" name="is_active" id="active-<?= (int) $slot['id'] ?>" <?= $slot['is_active'] ? 'checked' : '' ?>>
                <label for="active-<?= (int) $slot['id'] ?>" style="margin:0">Active</label>
              </span>
              <button class="btn btn--sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="warn-box">
    <strong>Before switching a slot to Network.</strong>
    Every ad network sets tracking cookies and loads JavaScript from a foreign
    server. That means the site would no longer be cookie-free, it would need a
    consent banner, and those slots would fail during an international blackout
    — which is the one thing the rest of the design is built to survive.
    House banners are served from your own database with no JavaScript and no
    cookies at all.
    Network mode additionally requires <code>ads.network.enabled = true</code>
    and the script origins listed in <code>app/config.local.php</code>, because
    turning it on also widens the Content-Security-Policy.
    Currently <strong><?= $networkEnabled ? 'enabled' : 'disabled' ?></strong> in config.
  </div>
</div>

<div class="grid grid--2">
  <div class="card">
    <p class="card__title">Page cache</p>
    <p><?= (int) $cacheFiles ?> page(s) cached on disk.</p>
    <p class="hint" style="margin-bottom:12px">
      Cached pages are served by the web server without starting PHP. Publishing
      clears the pages it affects; flush everything after a template change.
    </p>
    <form method="post" action="/admin/settings/flush-cache">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn" type="submit">Flush the cache</button>
    </form>
  </div>

  <div class="card">
    <p class="card__title">Worker tokens</p>
    <?php if ($tokens === []): ?>
      <p class="hint">
        None yet. Create one on the server with
        <code>php tools/worker-token.php "vps-name"</code>.
      </p>
    <?php else: ?>
      <table>
        <thead><tr><th>Name</th><th>Last used</th><th>Active</th></tr></thead>
        <tbody>
          <?php foreach ($tokens as $token): ?>
            <tr>
              <td><?= e($token['name']) ?></td>
              <td class="hint"><?= e((string) ($token['last_used_at'] ?? 'never')) ?></td>
              <td><span class="badge badge--<?= $token['is_active'] ? 'published' : 'archived' ?>">
                <?= $token['is_active'] ? 'yes' : 'no' ?>
              </span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin-top:10px">
        Only the hash of each token is stored, so a leaked database does not
        hand over working credentials.
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <p class="card__title">Currently blocked</p>
  <?php if ($blocked === []): ?>
    <div class="empty">Nothing is blocked.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Reason</th><th>Hits</th><th>Until</th></tr></thead>
      <tbody>
        <?php foreach ($blocked as $block): ?>
          <tr>
            <td><?= e($block['reason']) ?></td>
            <td><?= (int) $block['hits'] ?></td>
            <td class="hint"><?= e((string) $block['blocked_until']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="/admin/settings/unblock" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn" type="submit">Clear all blocks</button>
    </form>
  <?php endif; ?>
  <p class="hint" style="margin-top:10px">
    Addresses are stored only as a hash salted with a key that rotates daily,
    so these rows stop being linkable to a visitor after 24 hours.
  </p>
</div>
