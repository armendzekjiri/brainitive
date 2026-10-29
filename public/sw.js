/**
 * Brainitive service worker: PWA install, web push, and offline mode (BRAI-82).
 *
 * Offline mode is opt-in per device ("Work offline on this device"). The page caches the app
 * shell (`/offline` + the built files it needs) in SHELL_CACHE; this worker only reads it:
 * - a navigation that fails (no network) gets the cached shell, which renders My Day, recently
 *   opened task lists / issues and quick capture from IndexedDB — or a plain "you're offline" page;
 * - an Inertia visit (XHR) is never touched: when it fails for lack of network, the page itself
 *   switches to a full navigation (lib/offline/session.ts), which lands here and gets the shell.
 *   (Answering Inertia XHRs here broke Firefox: it never hands a redirected, intercepted response to
 *   the page — after a save or a link that redirects, the visit hung.)
 * - built assets come from the shell cache first (hashed, immutable).
 * Everything else — documents, live co-editing, API calls, POST/PUT — goes straight to the network:
 * no respondWith, so those requests behave exactly as without a service worker.
 */
const SHELL_CACHE = 'brainitive-offline-shell-v1';
const SHELL_URL = '/offline';
// caches of earlier versions of this worker (other features keep their own caches untouched)
const OLD_CACHES = ['brainitive-v1'];

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => OLD_CACHES.includes(k)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  let url;
  try {
    url = new URL(request.url);
  } catch (e) {
    return;
  }
  if (url.origin !== self.location.origin) return;

  if (url.pathname.startsWith('/build/assets/')) {
    event.respondWith(asset(request));
    return;
  }
  if (request.mode === 'navigate') {
    event.respondWith(navigation(request));
  }
});

async function asset(request) {
  const cached = await caches.match(request, { cacheName: SHELL_CACHE });
  if (cached) return cached;
  return fetch(request);
}

async function navigation(request) {
  try {
    return await fetch(request);
  } catch (error) {
    const shell = await caches.match(SHELL_URL, { cacheName: SHELL_CACHE });
    return shell || offlinePage();
  }
}

/** Offline mode is off on this device: a friendly page instead of the browser's error. */
function offlinePage() {
  const html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>You\'re offline - Brainitive</title><style>'
    + 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#F4F6F8;color:#485E72;padding:16px;box-sizing:border-box}'
    + 'main{max-width:420px;text-align:center}h1{font-size:1.4rem;color:#1f2937;margin:0 0 .5rem}p{line-height:1.5;margin:.5rem 0}'
    + 'a{display:inline-block;margin-top:1rem;background:#0E83DC;color:#fff;text-decoration:none;padding:.6rem 1.1rem;border-radius:.75rem}'
    + '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#cbd5e1}h1{color:#f1f5f9}}</style></head><body><main>'
    + '<h1>You\'re offline</h1><p>Brainitive needs a connection for this page.</p>'
    + '<p>Turn on <strong>Work offline on this device</strong> in your profile to keep using My Day, your task lists, issues and quick capture without internet.</p>'
    + '<a href="">Try again</a></main></body></html>';
  return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// ── Web Push ──────────────────────────────────────────────────────────────
// Payload is the JSON produced by WebPushMessage on the server:
// { title, body, icon, badge, data: { url } }.
self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = { title: 'Brainitive', body: event.data ? event.data.text() : '' };
  }

  const title = payload.title || 'Brainitive';
  const options = {
    body: payload.body || '',
    icon: payload.icon || '/android-chrome-192x192.png',
    badge: payload.badge || '/favicon-32x32.png',
    data: payload.data || {},
    tag: payload.tag,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const raw = (event.notification.data && event.notification.data.url) || '/';
  // Only open pages of this app (a notification link can never send you elsewhere)
  let url = '/';
  try {
    const parsed = new URL(raw, self.location.origin);
    if (parsed.origin === self.location.origin) url = parsed.href;
  } catch (e) {}

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // Focus an existing tab on the same origin if one is open.
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(url).catch(() => {});
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});
