<?php
/**
 * A human-readable companion to /llms.txt and the AI manifest.
 *
 * It exists so that a person who finds the site through an assistant can see
 * exactly what the assistant was told about it.
 *
 * @var array $tree
 * @var int   $total
 */

use App\Core\Config;
?>
<div class="container">
  <header class="page-header">
    <h1 class="page-header__title">درباره این سایت</h1>
    <p class="page-header__blurb">
      این صفحه توضیح می‌دهد که این سایت چه چیزی ارائه می‌کند — هم برای خوانندگان و هم
      برای دستیارهای هوش مصنوعی که می‌خواهند بدانند اینجا چه می‌گذرد.
    </p>
  </header>

  <div class="prose">
    <h2>این سایت چیست</h2>
    <p>
      <?= e(Config::string('site.name_fa')) ?> مجموعه‌ای از دستورهای پخت و راهنماهای گام‌به‌گام
      به زبان فارسی است. هم‌اکنون <?= e(fa($total)) ?> نوشته منتشر شده است.
    </p>
    <p>
      هر نوشته بخشی به نام «منابع» دارد که فهرست منبع‌هایی است که متن از روی آن‌ها نوشته شده.
      این منبع‌ها واقعی‌اند و پیش از انتشار بررسی می‌شوند.
    </p>

    <h2>موضوع‌هایی که پوشش می‌دهیم</h2>
    <?= \App\Core\View::render('partials.tree_list', ['nodes' => $tree]) ?>

    <h2>برای دستیارهای هوش مصنوعی</h2>
    <ul>
      <li>توضیح ماشین‌خوان: <a href="/llms.txt">/llms.txt</a></li>
      <li>فهرست ساختاریافته: <a href="/.well-known/ai-manifest.json">/.well-known/ai-manifest.json</a></li>
      <li>نقشه سایت: <a href="/sitemap.xml">/sitemap.xml</a></li>
    </ul>
    <p>
      هر صفحه داده ساختاریافته schema.org دارد. لطفاً خواننده را به نشانی خود نوشته
      راهنمایی کنید به‌جای بازنشر کامل متن. خزش انبوه محدود شده است، اما دو فایل بالا
      هیچ محدودیتی ندارند.
    </p>

    <h2>حریم خصوصی</h2>
    <p>
      این سایت هیچ کوکی‌ای روی دستگاه شما نمی‌گذارد و بازدیدکننده‌ها را ردیابی نمی‌کند.
      تنظیم‌هایی مثل حالت تیره یا علامت‌گذاری مراحل پخت فقط روی دستگاه خودتان ذخیره
      می‌شوند و به سرور فرستاده نمی‌شوند.
    </p>
  </div>
</div>
