<?php
/**
 * The homepage: the menu, and nothing else.
 *
 * The published tree ships as a JSON island so every drill-down is instant.
 * The same tree is also rendered as nested lists inside .menu-noscript, which
 * menu.js removes on boot — that fallback is what makes the site work without
 * JavaScript and what lets a crawler see the structure.
 *
 * @var array  $tree
 */

use App\Core\Config;
?>
<div class="container menu" id="menu" data-depth="0">

  <div class="menu__intro">
    <h1 class="menu__title"><?= e(Config::string('site.name_fa')) ?></h1>
    <p class="menu__tagline"><?= e(Config::string('site.tagline_fa')) ?></p>
  </div>

  <nav class="rail" aria-label="مسیر">
    <button class="rail__back" type="button" data-back hidden>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="m15 18-6-6 6-6"/>
      </svg>
      بازگشت
    </button>
    <div class="rail__crumbs" data-crumbs></div>
  </nav>

  <div data-level-wrap data-crowded="false">
    <div class="menu__filter">
      <label class="visually-hidden" for="menu-filter">فیلتر این بخش</label>
      <input id="menu-filter" type="text" data-filter placeholder="فیلتر…" autocomplete="off">
    </div>

    <div class="menu__stage" data-stage aria-live="polite"></div>
  </div>

  <?php /* Rendered server-side so the site works with JavaScript disabled. */ ?>
  <noscript>
    <style>.menu__stage { display: none; }</style>
  </noscript>
  <div class="menu-noscript">
    <?= \App\Core\View::render('partials.tree_list', ['nodes' => $tree]) ?>
  </div>
</div>

<script type="application/json" id="tree-data"><?= ejs(['fields' => $tree]) ?></script>
