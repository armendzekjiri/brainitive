/**
 * Minimal service worker for PWA install support.
 * Browsers (e.g. Chrome) require a registered service worker to show "Install app".
 */
const CACHE_NAME = 'brainitive-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  // Network-first: always try the network, no offline cache for now
  event.respondWith(fetch(event.request));
});

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
    icon: payload.icon || '/apple-touch-icon.png',
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
