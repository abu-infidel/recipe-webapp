<?php
/**
 * Shown when a page is requested with no connection and nothing cached for it.
 * Precached by the service worker, so it is always available.
 */
?>
<div class="container">
  <div class="empty-state">
    <p class="empty-state__title">اتصال اینترنت برقرار نیست</p>
    <p>
      این صفحه هنوز روی دستگاه شما ذخیره نشده است. صفحه‌هایی که پیش‌تر باز
      کرده‌اید بدون اینترنت هم در دسترس‌اند.
    </p>
    <p style="margin-block-start: var(--space-5);">
      <a href="/">بازگشت به صفحه اصلی</a>
    </p>
  </div>
</div>
