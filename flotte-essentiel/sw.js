// Service Worker - Flotte Pro PWA
const CACHE_NAME = 'flotte-pro-v1';
const ASSETS = [
  'login.html',
  'app.html',
  'manifest.json',
  'icon.png'
];

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(ASSETS).catch(function(){});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(names) {
      return Promise.all(
        names.filter(function(n) { return n !== CACHE_NAME; })
             .map(function(n) { return caches.delete(n); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  // Ne pas mettre en cache les appels API
  if (e.request.url.includes('api/')) {
    return;
  }
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      return cached || fetch(e.request).then(function(response) {
        return caches.open(CACHE_NAME).then(function(cache) {
          if (e.request.method === 'GET') {
            cache.put(e.request, response.clone());
          }
          return response;
        });
      }).catch(function() {
        return cached;
      });
    })
  );
});
