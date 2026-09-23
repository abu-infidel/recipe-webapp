/**
 * Service worker.
 *
 * The site is hosted inside Iran so that a blackout cutting international
 * routes leaves it reachable. This is the second layer: articles a reader has
 * already opened stay readable even with no connection at all.
 *
 * Nothing here is a tracking mechanism. The cache holds pages the reader
 * visited, on their own device, and is never read by the server.
 */

const VERSION = '__VERSION__';
const SHELL_CACHE = `shell-${VERSION}`;
const PAGE_CACHE = `pages-${VERSION}`;
const ASSET_CACHE = `assets-${VERSION}`;

// Everything needed to render something useful with no network at all:
// core assets plus the active theme's, injected by the server so this list
// can never drift from what the pages actually load.
const SHELL = __SHELL__;

// How many article pages to keep. Bounded so the cache cannot grow without
// limit on a phone with little storage.
const MAX_PAGES = 60;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      // addAll fails atomically if any single file 404s, which would leave the
      // worker uninstalled; adding individually degrades instead.
      .then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => !key.endsWith(VERSION))
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Only this origin. Nothing else should be loading anyway — the CSP is
  // self-only — but a service worker caching a foreign response would be a
  // way around that.
  if (url.origin !== self.location.origin) return;

  // Never cache the admin panel, contributor accounts or any API response:
  // those are personal or live, and a stale copy would be wrong or leak.
  if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/account') || url.pathname.startsWith('/api/')) return;

  if (isAsset(url.pathname)) {
    event.respondWith(cacheFirst(request, ASSET_CACHE));
    return;
  }

  event.respondWith(networkFirstWithOfflineFallback(request));
});

/**
 * Assets carry a ?v= fingerprint that changes when the file does, so serving
 * from cache first is safe and makes repeat visits instant.
 */
async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    return cached ?? Response.error();
  }
}

/**
 * Pages come from the network when there is one, so a reader always gets the
 * current version, and fall back to the cached copy when there is not.
 */
async function networkFirstWithOfflineFallback(request) {
  try {
    const response = await fetch(request);

    if (response.ok) {
      const cache = await caches.open(PAGE_CACHE);
      cache.put(request, response.clone());
      trimPageCache();
    }

    return response;
  } catch (error) {
    const cached = await caches.match(request);
    if (cached) return cached;

    const offline = await caches.match('/offline');
    if (offline) return offline;

    return new Response(
      '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8">' +
      '<title>آفلاین</title><body style="font-family:sans-serif;padding:2rem;text-align:center">' +
      '<h1>اتصال برقرار نیست</h1><p>این صفحه هنوز ذخیره نشده است.</p></body></html>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
    );
  }
}

/** Evict the oldest entries once the page cache passes its limit. */
async function trimPageCache() {
  const cache = await caches.open(PAGE_CACHE);
  const keys = await cache.keys();

  if (keys.length <= MAX_PAGES) return;

  for (const key of keys.slice(0, keys.length - MAX_PAGES)) {
    await cache.delete(key);
  }
}

function isAsset(pathname) {
  return pathname.startsWith('/assets/')
    || pathname.startsWith('/themes/')
    || pathname.startsWith('/media/')
    || pathname === '/manifest.webmanifest';
}

/**
 * "Save this section offline": the page sends a list of URLs and the worker
 * fetches them all into the page cache, so a whole branch can be read later
 * with no connection.
 */
self.addEventListener('message', (event) => {
  if (event.data?.type !== 'cache-urls') return;

  const urls = (event.data.urls ?? []).slice(0, 200);

  event.waitUntil(
    caches.open(PAGE_CACHE).then(async (cache) => {
      let saved = 0;

      for (const url of urls) {
        try {
          const response = await fetch(url, { credentials: 'omit' });
          if (response.ok) {
            await cache.put(url, response);
            saved++;
          }
        } catch {
          // One failure should not abandon the rest.
        }
      }

      const clients = await self.clients.matchAll();
      clients.forEach((client) => client.postMessage({ type: 'cached', saved, total: urls.length }));
    })
  );
});
