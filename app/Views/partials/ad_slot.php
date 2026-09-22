<?php
/**
 * One ad placement.
 *
 * Renders nothing at all when the slot has no creative, so an unsold slot
 * leaves no empty box on the page.
 *
 * @var string   $slot
 * @var int|null $fieldId
 */

use App\Domain\Ads;

$creative = Ads::creativeFor($slot, $fieldId ?? null);
$networkScript = $creative === null ? Ads::networkScriptFor($slot) : null;

if ($creative === null && $networkScript === null) {
    return;
}
?>
<aside class="ad-slot" aria-label="آگهی">
  <p class="ad-slot__label">آگهی</p>

  <?php if ($creative !== null): ?>
    <a class="ad-house" href="<?= e($creative['target_url']) ?>" rel="sponsored noopener" target="_blank">
      <?php if (!empty($creative['media_path'])): ?>
        <img src="/media/<?= e($creative['media_path']) ?>" alt="<?= e($creative['media_alt'] ?? '') ?>" loading="lazy" width="80" height="80">
      <?php endif; ?>
      <span>
        <span class="ad-house__title"><?= e($creative['title_fa']) ?></span>
        <?php if (!empty($creative['body_fa'])): ?>
          <span class="ad-house__body"><?= e($creative['body_fa']) ?></span>
        <?php endif; ?>
      </span>
    </a>
  <?php else: ?>
    <?php /* Third-party network slot. Only reachable while ads.network.enabled
             is true, which also widens the CSP — see Response::securityHeaders. */ ?>
    <div data-ad-network="<?= e($slot) ?>"></div>
    <script src="<?= e($networkScript) ?>" async></script>
  <?php endif; ?>
</aside>
