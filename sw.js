'use strict';
/* Canteen Access System — Service Worker v3.0 */

const CACHE_NAME = 'canteen-v3';
const SYNC_TAG   = 'canteen-offline-sync';

const PRECACHE_URLS = [
    '/index.php',
    '/login.php',
    '/assets/css/style.css',
    '/assets/js/app.js',
    '/assets/js/offline.js',
    '/assets/js/qr-input.js',
    '/manifest.json',
    '/logo.png',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            Promise.allSettled(
                PRECACHE_URLS.map(url => cache.add(url).catch(() => {}))
            )
        ).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    const skipPaths = [
        '/api/', '/api_sync.php', '/offline_sync.php', '/api_schedule.php',
        '/add_employee.php', '/update_employee.php', '/delete_employee.php',
        '/manual_pass.php', '/export_excel.php', '/get_employee.php',
    ];
    if (skipPaths.some(p => url.pathname === p || url.pathname.startsWith(p))) return;

    // Static assets — cache-first
    if (/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)$/.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then(cached => cached ||
                fetch(request).then(resp => {
                    if (resp && resp.status === 200 && resp.type === 'basic') {
                        caches.open(CACHE_NAME).then(c => c.put(request, resp.clone()));
                    }
                    return resp;
                })
            )
        );
        return;
    }

    // PHP pages — network-first, fallback to cache
    event.respondWith(
        fetch(request)
            .then(resp => {
                if (resp && resp.status === 200 && resp.type === 'basic') {
                    caches.open(CACHE_NAME).then(c => c.put(request, resp.clone()));
                }
                return resp;
            })
            .catch(() =>
                caches.match(request).then(c => c || caches.match('/index.php'))
            )
    );
});

self.addEventListener('sync', event => {
    if (event.tag === SYNC_TAG) {
        event.waitUntil(
            self.clients.matchAll({ type: 'window', includeUncontrolled: true })
                .then(clients => clients.forEach(c => c.postMessage({ type: 'DO_SYNC' })))
        );
    }
});

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
    if (event.data && event.data.type === 'GET_CACHE_INFO') {
        caches.open(CACHE_NAME).then(c => c.keys()).then(keys => {
            if (event.source) {
                event.source.postMessage({
                    type:  'CACHE_INFO_RESULT',
                    count: keys.length,
                    cache: CACHE_NAME,
                });
            }
        });
    }
});
