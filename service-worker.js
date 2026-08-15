const CACHE_NAME = 'cinebook-full-revised-v4';
const STATIC_ASSETS = [
  './css/style.css',
  './css/cinematic-awards.css',
  './css/cinebook-global-theme.css',
  './css/profile-system.css',
  './css/home-premium.css',
  './css/content-pages.css',
  './js/app.js',
  './js/cinebook-global-effects.js',
  './js/profile-system.js',
  './js/modules/cinematic-engine.js',
  './js/modules/page-scenes.js',
  './js/modules/qr-ticket.js',
  './images/header/logo.png',
  './images/header/favicon.ico',
  './images/content/theater_bg.jpg'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).catch(() => null));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (!url.pathname.match(/\.(css|js|png|jpg|jpeg|webp|gif|ico|woff|woff2)$/i)) return;

  event.respondWith(
    caches.match(event.request).then(cached => {
      const network = fetch(event.request).then(response => {
        if (response && response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
        }
        return response;
      }).catch(() => cached);
      return cached || network;
    })
  );
});
