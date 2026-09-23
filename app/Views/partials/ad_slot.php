<?php
/**
 * One ad placement.
 *
 * Renders nothing at all when the slot has nothing to show, so an unsold slot
 * leaves no empty box. House creatives travel as data and the browser picks
 * one (see site.js), because a creative chosen here would be frozen into the
 * cached page.
 *
 * @var string   $slot
 * @var int|null $fieldId
 */

use App\Domain\Ads;

$creatives = Ads::eligibleCreatives($slot, $fieldId ?? null);
$networkScript = $creatives === [] ? Ads::networkScriptFor($slot) : null;

if ($creatives === [] && $networkScript === null) {
    return;
}
?>
<aside class="ad-slot" aria-label="آگهی" data-ad-slot="<?= e($slot) ?>"<?= $creatives !== [] ? ' hidden' : '' ?>>
  <p class="ad-slot__label">آگهی</p>
  <?php if ($creatives !== []): ?>
    <script type="application/json" data-ad-creatives><?= ejs($creatives) ?></script>
  <?php else: ?>
    <?php /* Third-party network slot. Reachable only while ads.network.enabled
             is true, which also widens the CSP — see Response::securityHeaders. */ ?>
    <div data-ad-network="<?= e($slot) ?>"></div>
    <script src="<?= e($networkScript) ?>" async></script>
  <?php endif; ?>
</aside>
