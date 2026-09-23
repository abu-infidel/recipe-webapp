<?php
/**
 * @var list<array>  $themes   each with name, title, version, description, active, builtin, check
 * @var array<string,string> $samples  label => public path, for previews
 * @var array|null   $report   result of the last upload or activation
 * @var string       $maxUpload
 * @var string       $csrf
 */

$findings = static function (array $errors, array $warnings): string {
    $html = '';
    foreach ($errors as $error) {
        $html .= '<div class="finding finding--error"><span class="finding__code">error</span><span>' . e($error) . '</span></div>';
    }
    foreach ($warnings as $warning) {
        $html .= '<div class="finding finding--warn"><span class="finding__code">warn</span><span>' . e($warning) . '</span></div>';
    }

    return $html;
};
?>
<div class="admin-head">
  <h1>Themes</h1>
</div>

<?php if ($report !== null): ?>
  <div class="card">
    <p class="card__title"><?= e($report['title'] ?? '') ?></p>
    <?php if ($report['errors'] === [] && $report['warnings'] === []): ?>
      <p class="hint">No errors, no warnings.</p>
    <?php else: ?>
      <div class="findings"><?= $findings($report['errors'], $report['warnings']) ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <p class="card__title">Installed</p>
  <table>
    <thead><tr><th>Theme</th><th>Check</th><th>Preview</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($themes as $theme): $check = $theme['check']; ?>
        <tr>
          <td>
            <strong><?= e($theme['title']) ?></strong>
            <?php if ($theme['active']): ?><span class="badge badge--published">live</span><?php endif; ?>
            <div class="hint"><?= e($theme['name']) ?><?= $theme['version'] !== '' ? ' · v' . e($theme['version']) : '' ?></div>
            <?php if ($theme['description'] !== ''): ?><div class="hint"><?= e($theme['description']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($check['ok']): ?>
              <span class="badge badge--published">passes</span>
            <?php else: ?>
              <span class="badge badge--error"><?= count($check['errors']) ?> error(s)</span>
            <?php endif; ?>
            <?php if ($check['warnings'] !== []): ?>
              <span class="badge badge--in_review"><?= count($check['warnings']) ?> warning(s)</span>
            <?php endif; ?>
            <?php if ($check['errors'] !== [] || $check['warnings'] !== []): ?>
              <details style="margin-top:6px"><summary class="hint">Details</summary>
                <div class="findings" style="margin-top:6px"><?= $findings($check['errors'], $check['warnings']) ?></div>
              </details>
            <?php endif; ?>
          </td>
          <td>
            <?php foreach ($samples as $label => $path): ?>
              <a href="/admin/themes/<?= e($theme['name']) ?>/preview?path=<?= e(rawurlencode($path)) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><br>
            <?php endforeach; ?>
            <details><summary class="hint">Sample data</summary>
              <?php foreach (\App\Core\Template\Theme::PAGE_TYPES as $type): ?>
                <a href="/admin/themes/<?= e($theme['name']) ?>/preview?fixture=<?= e($type) ?>" target="_blank" rel="noopener"><?= e($type) ?></a>
              <?php endforeach; ?>
            </details>
          </td>
          <td style="white-space:nowrap">
            <?php if (!$theme['active'] && $check['ok']): ?>
              <form method="post" action="/admin/themes/<?= e($theme['name']) ?>/activate" style="display:inline"
                    data-confirm="Make &quot;<?= e($theme['title']) ?>&quot; the live theme? Every reader sees it immediately.">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--sm btn--primary" type="submit">Activate</button>
              </form>
            <?php endif; ?>
            <?php if (!$theme['active'] && !$theme['builtin']): ?>
              <form method="post" action="/admin/themes/<?= e($theme['name']) ?>/delete" style="display:inline"
                    data-confirm="Delete the theme &quot;<?= e($theme['name']) ?>&quot;? This removes its files.">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--sm btn--danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="grid grid--2">
  <div class="card">
    <p class="card__title">Upload a theme</p>
    <form method="post" action="/admin/themes/upload" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="theme-zip">Theme .zip</label>
        <input type="file" name="theme" id="theme-zip" accept=".zip,application/zip" required>
        <p class="hint">theme.json at the top of the archive, or inside one folder. This server accepts uploads up to <?= e($maxUpload) ?>.</p>
      </div>
      <div class="field">
        <span class="checkbox">
          <input type="checkbox" name="replace" id="theme-replace">
          <label for="theme-replace" style="margin:0">Replace a theme with the same name</label>
        </span>
      </div>
      <button class="btn btn--primary" type="submit">Upload and check</button>
    </form>
    <p class="hint" style="margin-top:12px">
      The theme is unpacked outside the web root and checked before anything is
      installed. It is never activated automatically.
    </p>
  </div>

  <div class="card">
    <p class="card__title">Having a theme made</p>
    <p>
      A theme is templates, CSS and JavaScript only. It cannot run code on the
      server, read the database or output unescaped HTML, so a new design
      cannot break the backend.
    </p>
    <p>
      To have one made by a language model, give it this site's address and
      ask it to read <code>/api/v1/schema</code> first. That document, plus
      <code>/api/v1/theme</code> (the current templates) and
      <code>/api/v1/page?path=…</code> (real data for any page), is everything
      it needs; <code>docs/UI-CONTRACT.md</code> in the repository says the
      same in prose.
    </p>
    <div class="warn-box">
      <strong>Theme JavaScript runs with the site's authority.</strong>
      Before activating a theme from someone else, read its warnings: anything
      touching cookies or <code>/admin</code> has no reason to.
    </div>
  </div>
</div>
