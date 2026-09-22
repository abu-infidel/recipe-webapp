<?php
/**
 * The recipe facts panel: times, yield, a serving scaler and the ingredients.
 *
 * The scaler rewrites quantities in place with no page load and no request.
 * Ticked-off steps are remembered per device in localStorage — no cookie,
 * nothing sent to the server.
 *
 * @var array $recipe
 * @var array $article
 */
$ingredients = $recipe['ingredients'] ?? [];
$steps = $recipe['steps'] ?? [];
?>
<div class="recipe-panel">

  <div class="recipe-facts">
    <?php if (!empty($recipe['prep_minutes'])): ?>
      <span class="chip">آماده‌سازی: <?= e(fa((int) $recipe['prep_minutes'])) ?> دقیقه</span>
    <?php endif; ?>
    <?php if (!empty($recipe['cook_minutes'])): ?>
      <span class="chip">پخت: <?= e(fa((int) $recipe['cook_minutes'])) ?> دقیقه</span>
    <?php endif; ?>
    <?php if (!empty($recipe['difficulty'])): ?>
      <span class="chip">سختی: <?= e($recipe['difficulty']) ?></span>
    <?php endif; ?>
    <?php if (!empty($recipe['yield_number'])): ?>
      <span class="chip">
        <span data-base-yield="<?= e((string) $recipe['yield_number']) ?>"><?= e(fa((int) $recipe['yield_number'])) ?></span>
        <?= e($recipe['yield_unit'] ?? 'نفر') ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if ($ingredients !== []): ?>
    <div class="scaler" data-scaler>
      <span>مقدار برای:</span>
      <span class="scaler__buttons" role="group" aria-label="تغییر مقدار مواد">
        <button type="button" data-factor="0.5" aria-pressed="false">نصف</button>
        <button type="button" data-factor="1" aria-pressed="true">اصلی</button>
        <button type="button" data-factor="2" aria-pressed="false">دو برابر</button>
        <button type="button" data-factor="3" aria-pressed="false">سه برابر</button>
      </span>
    </div>

    <div>
      <h2 id="ingredients">مواد لازم</h2>
      <ul class="ingredients">
        <?php foreach ($ingredients as $ingredient): ?>
          <li>
            <span class="ingredients__amount">
              <?php if (isset($ingredient['quantity']) && is_numeric($ingredient['quantity'])): ?>
                <span data-base-amount="<?= e((string) $ingredient['quantity']) ?>"><?= e(fa((string) $ingredient['quantity'])) ?></span>
              <?php elseif (!empty($ingredient['quantity'])): ?>
                <span><?= e((string) $ingredient['quantity']) ?></span>
              <?php endif; ?>
              <?= e($ingredient['unit'] ?? '') ?>
            </span>
            <span><?= e($ingredient['name'] ?? '') ?><?php
              if (!empty($ingredient['note'])) { echo ' <small>(' . e($ingredient['note']) . ')</small>'; }
            ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($steps !== []): ?>
    <div>
      <h2 id="steps">مراحل پخت</h2>
      <ol class="steps" data-steps data-article="<?= (int) $article['id'] ?>">
        <?php foreach ($steps as $i => $step): ?>
          <li>
            <input type="checkbox" id="step-<?= (int) $i ?>" aria-label="مرحله <?= e(fa($i + 1)) ?> انجام شد">
            <label class="steps__text" for="step-<?= (int) $i ?>">
              <span class="steps__number"><?= e(fa($i + 1)) ?>.</span>
              <?= e($step['text'] ?? '') ?>
            </label>
          </li>
        <?php endforeach; ?>
      </ol>
      <p><button class="rail__back" type="button" data-steps-reset>پاک کردن علامت‌ها</button></p>
    </div>
  <?php endif; ?>
</div>
