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
