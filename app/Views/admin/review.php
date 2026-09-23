<?php
/**
 * The review screen.
 *
 * Persian draft on the left, the sources it was written from on the right.
 * Every citation marker is clickable and scrolls the source pane to the text
 * that supports the claim; every validator finding links to the paragraph it
 * is about. This is where a person decides, which is the point of the whole
 * draft-first workflow.
 *
 * @var array  $article
 * @var array  $field
 * @var array  $sources
 * @var array  $citations
 * @var array  $flags
 * @var array  $events
 * @var string $csrf
 */

$errors = array_filter($flags, static fn($f) => ($f['severity'] ?? '') === 'error');
$warnings = array_filter($flags, static fn($f) => ($f['severity'] ?? '') === 'warn');
?>
<div class="admin-head">
  <div>
    <h1 class="fa" style="max-width:48ch"><?= e($article['title_fa']) ?></h1>
    <p class="hint" style="margin-top:6px">
      <span class="badge badge--<?= e($article['status']) ?>"><?= e($article['status']) ?></span>
      <?= e($article['kind']) ?> ·
      <?php /* bdi isolates the Persian name so it cannot reorder the English
               around it — without it the bidi algorithm drags neighbouring
               words across the label. */ ?>
      <bdi><?= e($field['title_fa'] ?? '') ?></bdi> ·
      <?= count($sources) ?> source(s) ·
      version <?= (int) $article['version'] ?>
      <?php if (!empty($article['ai_model'])): ?> · written by <?= e($article['ai_model']) ?><?php endif; ?>
    </p>
  </div>

  <div style="display:flex; gap:8px; flex-wrap:wrap">
    <?php if (\App\Domain\ArticleComposer::isComposerDocument($article['body_json'] ?? null)): ?>
      <a class="btn" href="/admin/articles/<?= (int) $article['id'] ?>/edit">Edit in composer</a>
    <?php endif; ?>
    <?php if ($article['status'] === 'published' && $publicUrl !== null): ?>
      <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">View live ↗</a>
      <form method="post" action="/admin/articles/<?= (int) $article['id'] ?>/unpublish"
            data-confirm="Take this article off the public site?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--danger" type="submit">Unpublish</button>
      </form>
    <?php else: ?>
      <form method="post" action="/admin/articles/<?= (int) $article['id'] ?>/publish"
            <?= $errors !== [] ? 'data-confirm="This draft has unresolved citation errors. Publish anyway?"' : '' ?>>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--primary" type="submit">Publish</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($flags !== []): ?>
  <div class="card">
    <p class="card__title">
      Validator findings —
      <?= count($errors) ?> error(s), <?= count($warnings) ?> warning(s)
    </p>
    <div class="findings">
      <?php foreach ($flags as $flag): ?>
        <div class="finding finding--<?= e($flag['severity'] ?? 'warn') ?>">
          <span class="finding__code"><?= e($flag['code'] ?? '') ?></span>
          <span>
            <?= e($flag['message'] ?? '') ?>
            <?php if (!empty($flag['anchor'])): ?>
              — <a href="#<?= e($flag['anchor']) ?>" data-jump="<?= e($flag['anchor']) ?>">go to paragraph</a>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($errors !== []): ?>
      <div class="warn-box">
        An error means the draft cites something that is not in the source list.
        Fix or remove the claim before publishing.
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="flash flash--ok">
    Every citation resolves to a fetched source, and every figure appears in a
    source that paragraph cites.
  </div>
<?php endif; ?>

<div class="review" style="margin-top:16px">

  <div>
    <div class="card">
      <p class="card__title">Draft, as a reader will see it</p>
      <div class="draft-body fa" data-draft>
        <?php if (!empty($article['summary_fa'])): ?>
          <p style="font-weight:500; border-inline-start:3px solid var(--a-accent); padding-inline-start:10px">
            <?= e($article['summary_fa']) ?>
          </p>
        <?php endif; ?>
        <?php /* Sanitised again for display, whatever is stored. This screen
                 runs with the admin session, so it is the last place an
                 unsanitised draft should ever be rendered raw. */ ?>
        <?= \App\Support\HtmlSanitizer::clean((string) $article['body_html']) ?>
      </div>
    </div>

    <details class="card">
      <summary style="cursor:pointer; font-weight:600">Edit</summary>
      <form method="post" action="/admin/articles/<?= (int) $article['id'] ?>/save" style="margin-top:14px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="field">
          <label for="title_fa">Title (Persian)</label>
          <input class="fa" type="text" id="title_fa" name="title_fa" value="<?= e($article['title_fa']) ?>">
        </div>

        <div class="field">
          <label for="summary_fa">Summary (Persian)</label>
          <textarea class="fa" id="summary_fa" name="summary_fa" style="min-height:80px"><?= e((string) $article['summary_fa']) ?></textarea>
        </div>

        <div class="field">
          <label for="body_html">Body HTML</label>
          <textarea id="body_html" name="body_html" style="min-height:340px"><?= e((string) $article['body_html']) ?></textarea>
          <p class="hint">
            Sanitised against an allow-list at publish time, so anything unusual
            is stripped. Citation markers look like
            <code>&lt;a class="cite" href="#ref-1"&gt;۱&lt;/a&gt;</code>.
          </p>
        </div>

        <button class="btn btn--primary" type="submit">Save changes</button>
      </form>
    </details>

    <?php if ($events !== []): ?>
      <details class="card">
        <summary style="cursor:pointer; font-weight:600">Pipeline log</summary>
        <div class="events" style="margin-top:12px">
          <?php foreach ($events as $event): ?>
            <div class="events__row">
              <span><?= e(date('H:i:s', (int) strtotime((string) $event['created_at']))) ?></span>
              <span class="events__level--<?= e($event['level']) ?>"><?= e($event['stage']) ?></span>
              <span><?= e($event['message']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <div class="review__sources">
    <p class="card__title">Sources</p>

    <?php if ($sources === []): ?>
      <div class="empty">No sources recorded for this article.</div>
    <?php endif; ?>

    <?php foreach ($sources as $source): ?>
      <div class="source" id="source-<?= (int) $source['marker'] ?>" data-marker="<?= (int) $source['marker'] ?>">
        <div class="source__head">
          <span class="source__marker">[<?= (int) $source['marker'] ?>]</span>
          <span class="source__title"><?= e($source['title'] ?: $source['url']) ?></span>
          <span class="source__tier">tier <?= (int) $source['trust_tier'] ?></span>
        </div>

        <div class="source__meta">
          <?php if (\App\Support\UrlGuard::isHttpUrl($source['url'])): ?>
            <a href="<?= e($source['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($source['domain']) ?></a>
          <?php else: ?>
            <?= e($source['domain']) ?>
          <?php endif; ?>
          <?php if (!empty($source['author'])): ?> · <?= e($source['author']) ?><?php endif; ?>
          <?php if (!empty($source['published_date'])): ?> · <?= e((string) $source['published_date']) ?><?php endif; ?>
          · fetched <?= e(date('Y-m-d', (int) strtotime((string) $source['fetched_at']))) ?>
        </div>

        <?php if (!empty($source['extracted_text'])): ?>
          <div class="source__text"><?= e(mb_substr((string) $source['extracted_text'], 0, 2600, 'UTF-8')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
